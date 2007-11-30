#!/usr/bin/perl -w
# -*- mode: Perl; tab-width: 4; -*-
#
# create-finkdb.pl - generate a runtime index of Fink's package database
# Copyright (c) 2001 Christoph Pfisterer
# Copyright (c) 2001-2007 The Fink Package Manager Team
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# as published by the Free Software Foundation; either version 2
# of the License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
#

$| = 1;
use 5.008_001;  # perl 5.8.1 or newer required
use strict;
use warnings;

use Cwd qw(abs_path getcwd);
use File::Basename;
use File::Slurp;
use Text::CSV_XS;

our $topdir;
our $fink_version;

BEGIN
{
	$topdir = dirname(abs_path($0));
	chomp($fink_version = read_file($topdir . '/fink/VERSION'));

	my $finkversioncontents = read_file($topdir . '/fink/perlmod/Fink/FinkVersion.pm.in');
	$finkversioncontents =~ s/\@VERSION\@/$fink_version/gs;
	write_file($topdir . '/fink/perlmod/Fink/FinkVersion.pm', $finkversioncontents);
};

### now load the useful modules

use lib qw(fink/perlmod);
use Fink::Services qw(&read_config &latest_version);
use Fink::Config qw(&set_options);
use Fink::Package;
use Fink::Command qw(rm_f);
use File::Find;
use File::Path;
use File::stat;
use POSIX qw(strftime);
use Time::HiRes qw(usleep);
use Data::Dumper;
use IO::File;
use XML::Writer;
use LWP::UserAgent;
use HTTP::Request::Common qw(POST);
use URI;
use URI::QueryParam;
use XML::DOM;

use Encode;
use Text::Iconv;
use Getopt::Long;

$Data::Dumper::Deepcopy = 1;

use vars qw(
	$debug
	$xmldir
	$tempdir
	$trace
	$wanthelp

	$csv
	$iconv

	$releases
	$solr_url

	$disable_cvs
	$disable_indexing
	$disable_solr
	$disable_delete

	$ua
);

$csv          = Text::CSV_XS->new({ binary => 1 });
$debug        = 0;
$trace        = 0;
$iconv        = Text::Iconv->new("UTF-8", "UTF-8");
$solr_url     = 'http://localhost:8983/solr';
$tempdir      = $topdir . '/work';
$xmldir       = $tempdir . '/xml';

$disable_cvs      = 0;
$disable_indexing = 0;
$disable_solr     = 0;
$disable_delete   = 0;

$ua = LWP::UserAgent->new();

# process command-line
GetOptions(
	'help'             => \$wanthelp,
	'xmldir=s'         => \$xmldir,
	'tempdir=s'        => \$tempdir,
	'verbose'          => \$debug,
	'trace'            => \$trace,

	'url',             => \$solr_url,

	'disable-cvs'      => \$disable_cvs,
	'disable-indexing' => \$disable_indexing,
	'disable-solr'     => \$disable_solr,
	'disable-delete'   => \$disable_delete,
) or &die_with_usage;

$debug++ if ($trace);

&die_with_usage if $wanthelp;

# get the list of distributions to scan
{
	my $distributions;

	print "- parsing distribution/release information\n";

	open (GET_DISTRIBUTIONS, $topdir . '/php-lib/get_distributions.php |') or die "unable to run $topdir/php-lib/get_distributions.php: $!";
	my @keys = parse_csv(scalar(<GET_DISTRIBUTIONS>));

	while (my $line = <GET_DISTRIBUTIONS>)
	{
		my @values = parse_csv($line);
		my $entry = make_hash(\@keys, \@values);
		my $id = $entry->{'id'};
		$distributions->{$id} = $entry;
	}
	close(GET_DISTRIBUTIONS);

	open (GET_RELEASES, $topdir . '/php-lib/get_distributions.php -r |') or die "unable to run $topdir/php-lib/get_distributions.php -r: $!";
	@keys = parse_csv(scalar(<GET_RELEASES>));

	while (my $line = <GET_RELEASES>) 
	{
		my @values = parse_csv($line);
		my $entry = make_hash(\@keys, \@values);
		my $id = $entry->{'id'};

		print "  - found $id\n" if ($debug);

		my $distribution_id = delete $entry->{'distribution_id'};
		$distributions->{$distribution_id} = {} unless (exists $distributions->{$distribution_id});
		$entry->{'distribution'} = $distributions->{$distribution_id};

		$releases->{$id} = $entry;
	}
	close (GET_RELEASES);
}

print Dumper($releases), "\n" if ($trace);

for my $release (sort keys %$releases)
{
	next unless ($releases->{$release}->{'isactive'});

	unless ($disable_cvs)
	{
		print "- checking out $release\n";
		check_out_release($releases->{$release});
	}

	unless ($disable_indexing)
	{
		print "- indexing $release\n";
		index_release_to_xml($releases->{$release});
	}

	unless ($disable_solr)
	{
		print "- posting $release to solr\n";
		post_release_to_solr($releases->{$release});
	}

	unless ($disable_delete)
	{
		print "- removing obsolete $release files\n";
		remove_obsolete_entries($releases->{$release});
	}
}

sub check_out_release
{
	my $release = shift;
	my $release_id = $release->{'id'};

	my $tag = get_tag_name($release->{'version'});
	my $checkoutroot = get_basepath($release) . '/fink';
	my $workingdir   = $checkoutroot;

	my @command = (
		'cvs',
		'-d', ':pserver:anonymous@fink.cvs.sourceforge.net:/cvsroot/fink',
		'checkout',
		'-r', $tag,
		'-d', 'dists',
		$release->{'distribution'}->{'rcspath'}
	);

	if (-e $checkoutroot . '/dists/CVS/Repository')
	{
		chomp(my $repo = read_file($checkoutroot . '/dists/CVS/Repository'));
		if ($repo eq $release->{'distribution'}->{'rcspath'})
		{
			@command = ( 'cvs', 'update', '-r', $tag );
			$workingdir = $checkoutroot . '/dists';
		} else {
			rmtree($checkoutroot . '/dists');
		}
	}

	run_command($workingdir, @command);
}

sub index_release_to_xml
{
	my $release = shift;
	my $release_id = $release->{'id'};

	my $tree = $release->{'type'};
	$tree = 'stable' if ($tree eq 'bindist');
	my $basepath = get_basepath($release);
	mkpath($basepath . '/var/lib/fink');

	undef $Fink::Package::packages;
	undef $Fink::Config::config;

	open(OLDOUT, ">&STDOUT");
	open(OLDERR, ">&STDERR");
	if ($trace)
	{
		# temporarily redirect stdout to stderr
		open(STDOUT, ">&STDERR") or die "can't dup STDERR: $!";
	} else {
		# temporarily ignore stdout/stderr
		open(STDOUT, ">/dev/null") or die "can't ignore STDOUT: $!";
		open(STDERR, ">/dev/null") or die "can't ignore STDERR: $!";
	}

	# keep 'use strict' happy
	select(OLDOUT); select(STDOUT);
	select(OLDERR); select(STDERR);

	# simulate a fink.conf; there's no actual file, so don't save() it
	my $config = Fink::Config->new_from_properties(
		{
			'basepath'     => $basepath,
			'trees'        => "$tree/main $tree/crypto",
			'distribution' => $release->{'distribution'}->{'name'},
			'architecture' => $release->{'distribution'}->{'architecture'},
		}
	);

	my @packagefiles;
	find(
		{
			wanted => sub {
				return unless (/\.info$/);
				push(@packagefiles, Fink::PkgVersion->pkgversions_from_info_file($File::Find::name));
			},
			nochdir => 1,
		},
		$basepath . '/fink/dists/' . $tree
	);

	# load the package database
	Fink::Package->load_packages();

	# put STDOUT back
	open(STDOUT, ">&OLDOUT");
	if (not $trace)
	{
		# we ignored STDERR, put it back
		open(STDERR, ">&OLDERR");
	}

	print $release->{'id'} . " trees = " . $config->param("trees"), "\n";

	### loop over packages

	my ($packageobj);
	my ($maintainer, $email, $desc, $usage, $parent, $infofile, $infofilechanged);
	my ($v, $s, $key, %data, $expand_override);

	#foreach $package (Fink::Package->list_packages())
	foreach $packageobj (@packagefiles)
	{
		# get info file
		$infofile = $packageobj->get_info_filename();

		next if (not defined $infofile or not -f $infofile);

		if ($infofile)
		{
			my $sb = stat($infofile);
			#$infofilechanged = strftime "%Y-%m-%d %H:%M:%S", localtime $sb->mtime;
			$infofilechanged = strftime "%Y-%m-%dT%H:%M:%SZ", localtime $sb->mtime;
			$infofile =~ s,$basepath/fink/dists/,,;
		}
		
		# gather fields
	
		$maintainer = $packageobj->param_default("Maintainer", "(not set)");
	
		# Always show %p as '/sw'
		$expand_override->{'p'} = '/sw';
	
		$desc = $packageobj->param_default_expanded('DescDetail', '',
			expand_override => $expand_override,
			err_action => 'ignore'
		);
		chomp $desc;
		$desc =~ s/\s+$//s;
		#$desc =~ s/\n/\\n/g;
	 
		$usage = $packageobj->param_default_expanded('DescUsage', '',
			expand_override => $expand_override,
			err_action => 'ignore'
		);
		chomp $usage;
		$usage =~ s/[\r\n\s]+$//s;
		#$usage =~ s/\n/\\n/g;
	
		my $parent = undef;
		if ($packageobj->has_parent())
		{
			$parent = {
				name     => $packageobj->get_parent()->get_name(),
				version  => $packageobj->get_parent()->get_version(),
				revision => $packageobj->get_parent()->get_revision(),
				epoch    => $packageobj->get_parent()->get_epoch(),
			};
		}

		my $package_info = {
			name              => $packageobj->get_name(),
			version           => $packageobj->get_version(),
			revision          => $packageobj->get_revision(),
			epoch             => $packageobj->get_epoch(),
			descshort         => $packageobj->get_shortdescription(),
			desclong          => $desc,
			descusage         => $usage,
			maintainer        => $maintainer,
			license           => $packageobj->get_license(),
			homepage          => $packageobj->param_default("Homepage", ""),
			section           => $packageobj->get_section(),
			parentname        => package_id($parent),
			infofile          => $infofile,
			rcspath           => $release->{'distribution'}->{'rcspath'} . '/' . $infofile,
			tag               => get_tag_name($release->{'version'}),
			infofilechanged   => $infofilechanged,
			dist_id           => $release->{'distribution'}->{'id'},
			dist_name         => $release->{'distribution'}->{'name'},
			dist_architecture => $release->{'distribution'}->{'architecture'},
			dist_description  => $release->{'distribution'}->{'description'},
			dist_active       => $release->{'distribution'}->{'isactive'}? 'true':'false',
			dist_visible      => $release->{'distribution'}->{'isvisible'}? 'true':'false',
			dist_supported    => $release->{'distribution'}->{'issupported'}? 'true':'false',
			rel_id            => $release->{'id'},
			rel_type          => $release->{'type'},
			rel_version       => $release->{'version'},
			rel_priority      => $release->{'priority'},
			rel_active        => $release->{'isactive'}? 'true':'false',
		};

		for my $key (keys %$package_info)
		{
			#$package_info->{$key} =~ s/(\x{ca}|\x{a8}|\x{e96261})/ /gs if (defined $package_info->{$key});
			$package_info->{$key} = encode_utf8($package_info->{$key}) if (defined $package_info->{$key});
		}

		print "  - ", package_id($package_info), "\n" if ($debug);

		my $xmlpath = get_xmlpath($release);
		mkpath($xmlpath);

		my $outputfile = $xmlpath . '/' . package_id($package_info) . '.xml';
		my $xml;

		my $writer = XML::Writer->new(OUTPUT => \$xml);

		# alternate schema, solr
		$writer->startTag("add");
		$writer->startTag("doc");

		$writer->startTag("field", "name" => "pkg_id");
		$writer->characters(package_id($package_info));
		$writer->endTag("field");

		$writer->startTag("field", "name" => "doc_id");
		$writer->characters($release->{'id'} . '-' . package_id($package_info));
		$writer->endTag("field");

		for my $key (keys %$package_info)
		{
			if (defined $package_info->{$key})
			{
				$writer->startTag("field", "name" => $key);
				$writer->characters($package_info->{$key});
				$writer->endTag("field");
			}
		}

		$writer->endTag("doc");
		$writer->endTag("add");

		$writer->end();

		my $output = IO::File->new('>' . $outputfile);
		print $output $xml;
		$output->close();
	}
}

sub post_release_to_solr
{
	my $release = shift;
	my $release_id = $release->{'id'};

	my $xmlpath = get_xmlpath($release);

	find(
		{
			wanted => sub {
				return unless (/.xml$/);
				my $file = $_;
				post_to_solr($file);
			},
			no_chdir => 1,
		},
		$xmlpath,
	);
}

sub remove_obsolete_entries
{
	my $release = shift;
	my $release_id = $release->{'id'};

	my $xmlpath = get_xmlpath($release);
	my $basepath = get_basepath($release);
	find(
		{
			wanted => sub {
				return unless (/.xml$/);
				my $file = $_;

				print "file = $file\n" if ($trace);

				my $contents = read_file($file);
				my ($doc_id)   = $contents =~ /<field name="doc_id">([^<]+)/;
				my ($name)     = $contents =~ /<field name="name">([^<]+)/;
				my ($infofile) = $contents =~ /<field name="infofile">([^<]+)/;

				return unless (defined($doc_id) and defined($infofile));

				my $infofilename = $basepath . '/fink/dists/' . $infofile;
				if (-f $infofilename)
				{
					print "  - package $name is still valid ($infofile)\n" if ($trace);
				} else {
					print "  - package $name is obsolete ($infofile)\n" if ($debug);
					post_to_solr('<delete><query>+doc_id:"' . $doc_id . '"</query></delete>');
					unlink($file);
				}
			},
			no_chdir => 1,
		},
		$xmlpath,
	);

	# second pass; in theory this should never be an issue, but it's possible
	# to have stale stuff in the index if it gets out-of-sync
	my $packages = get_packages_from_solr( '+rel_id:' . $release_id, 'doc_id,pkg_id,infofile,name' );
	for my $package (@{$packages})
	{
		my $infofilename = $basepath . '/fink/dists/' . $package->{'infofile'};
		if (-f $infofilename)
		{
			print "  - package ", $package->{'name'}, " is still valid (", $package->{'infofile'}, ")\n" if ($trace);
		} else {
			print "  - package ", $package->{'name'}, " is obsolete (", $package->{'infofile'}, ")\n" if ($debug);
			post_to_solr('<delete><query>+doc_id:"' . $package->{'doc_id'} . '"</query></delete>');
		}
	}
}

# get the name of a CVS tag given the version
sub get_tag_name
{
	my $release_version = shift;

	my $tag = 'release_' . $release_version;
	$tag =~ s/\./_/gs;
	if ($tag eq "release_current")
	{
		$tag = 'HEAD';
	}

	return $tag;
}

# get the info file path for a given release
sub get_xmlpath
{
	my $release = shift;
	return $xmldir . '/' . $release->{'id'};
}

# get the basepath for a given release
sub get_basepath
{
	my $release = shift;

	return $tempdir . '/basepath/' . $release->{'id'};
}

# run a command in a work directory
sub run_command
{
	my $workingdir = shift;
	my @command = @_;

	mkpath($workingdir);

	my $fromdir = getcwd();
	print "  - changing directory to $workingdir\n" if ($trace);
	chdir($workingdir);

	print "  - running: @command\n" if ($debug);
	open(RUN, "@command |") or die "unable to run @command: $!";
	while (<RUN>)
	{
		print "  - " . $_ if ($trace);
	}
	close(RUN);

	print "  - changing directory to $fromdir\n" if ($trace);
	chdir($fromdir);
}

# create a package ID from package information
# this needs to be kept in sync with php-lib/finkinfo.inc
sub package_id
{
	my $package = shift;

	my $id = $package->{'name'};
	if ($package->{'epoch'})
	{
		$id .= '-' . $package->{'epoch'};
	}
	if (exists $package->{'version'})
	{
		if ($package->{'epoch'})
		{
			$id .= ':' . $package->{'version'};
		} else {
			$id .= '-' . $package->{'version'};
		}
	}
	$id .= '-' . $package->{'revision'} if (exists $package->{'revision'});

	return $id;
}

# turn two sets of array references into key => value pairs
sub make_hash
{
	my $keys   = shift;
	my $values = shift;

	my $return;
	for my $index ( 0 .. $#$keys )
	{
		$return->{$keys->[$index]} = $values->[$index];
		if ($values->[$index] eq "")
		{
			$return->{$keys->[$index]} = undef;
		}
	}

	return $return;
}

# parse a csv line
sub parse_csv
{
	my $row = shift;
	chomp($row);
	if ($csv->parse($row))
	{
		return $csv->fields();
	} else {
		warn "unable to parse '$row'\n";
	}
	return [];
}


sub post_to_solr
{
	my $data = shift;

	my $req = HTTP::Request->new(POST => $solr_url . '/update');
	$req->content_type('text/xml; charset=utf-8');

	# post the data
	if (-f $data)
	{
		$req->content(scalar read_file($data));
	} else {
		$req->content($data);
	}

	my $response = $ua->request($req);
	if ($response->is_error())
	{
		die "failed to post update: " . $response->status_line() . "\ncontent was:\n" . $req->content;
	}

	# commit the data
	$req->content('<commit />');
	$response = $ua->request($req);
	if ($response->is_error())
	{
		die "failed to commit update: " . $response->status_line();
	}
}

sub get_packages_from_solr
{
	my $query  = shift;
	my $fields = shift;

	my $uri = URI->new($solr_url . '/select');
	$uri->query_param( q       => $query );
	$uri->query_param( version => '2.2' );
	$uri->query_param( start   => 0 );
	$uri->query_param( rows    => 100000 );
	$uri->query_param( indent  => 'on' );

	if (defined $fields)
	{
		$uri->query_param( fl => $fields );
	}

	my $req = HTTP::Request->new(GET => $uri);
	my $response = $ua->request($req);
	if ($response->is_error())
	{
		die "failed to get $query: " . $response->status_line();
	}

	my $parser = XML::DOM::Parser->new();
	my $xml = $parser->parse($response->decoded_content());

	my $return = [];

	my $documents = $xml->getElementsByTagName("doc");
	my $num_docs = $documents->getLength();
	print "  - get_packages_from_solr($query) found $num_docs documents\n" if ($debug);
	for (my $i = 0; $i < $num_docs; $i++)
	{
		my $package = {};

		my $doc = $documents->item($i);
		for my $field ($doc->getChildNodes())
		{
			my $field_name;
			for my $attr ($field->getAttributes())
			{
				next unless (defined $attr);
				if ($attr->getNamedItem("name"))
				{
					$field_name = $attr->getNamedItem("name")->getNodeValue();
				}
			}
			for my $child ($field->getChildNodes())
			{
				if ($child->getNodeValue() ne "")
				{
					$package->{$field_name} = $child->getNodeValue();
				}
			}
		}
		push(@{$return}, $package);
	}

	return $return;
}

sub die_with_usage
{
    die <<EOMSG;
Usage: $0 [options]

Options:
	--help              this help
	--verbose           verbose output
	--trace             extremely verbose output

	--url=<path>        where SOLR's root is (default: http://localhost:8983/solr)
	--tempdir=<path>    where to put temporary files
	--xmldir=<path>     where to write the .xml files

	--disable-cvs       don't check out .info files
	--disable-indexing  don't index .info files to .xml files
	--disable-solr      don't post updated .xml files to solr
	--disable-delete    don't delete outdated packages

EOMSG
}

# vim: ts=4 sw=4 noet
