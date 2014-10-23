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
use Math::BigInt;
use Proc::ProcessTable;
use Text::CSV_XS;
use utf8;

our $topdir;
our $fink_version;

BEGIN
{
	$topdir = dirname(abs_path($0));
	chomp($fink_version = read_file($topdir . '/fink/VERSION', binmode => ':utf8'));

	my $finkversioncontents = read_file($topdir . '/fink/perlmod/Fink/FinkVersion.pm.in', binmode => ':utf8');
	$finkversioncontents =~ s/\@VERSION\@/$fink_version/gs;
	write_file($topdir . '/fink/perlmod/Fink/FinkVersion.pm', $finkversioncontents);
};

### now load the useful modules

use lib qw(fink/perlmod);
use Fcntl qw(:DEFAULT :flock);
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
	$pause
	$end_at
	$start_at
	$tempdir
	$trace
	$wanthelp
	$xmldir

	$csv
	$iconv

	$releases
	$solr_temp_port
	$solr_temp_path
	$solr_url
	$solr
	$solr_post_chunk_size

	$sql_driver
	$sql_host
	$sql_db
	$sql_port
	$sql_user
	$sql_pass

	$clear_db
	$keep_temporary
	$disable_cvs
	$disable_indexing
	$disable_solr
	$disable_local_solr
	$disable_delete

	$debbaseurl

	$backend

	$ua
	$pkgs
	$db
);

$csv            = Text::CSV_XS->new({ binary => 1 });
$debug          = 0;
$trace          = 0;
$iconv          = Text::Iconv->new("UTF-8", "UTF-8");
$pause          = 10;
$solr_temp_port = 1234;
$solr_url       = "http://127.0.0.1:$solr_temp_port/solr";
$tempdir        = $topdir . '/work';
$xmldir         = $tempdir . '/xml';
$start_at       = '';
$end_at         = '';
$solr_temp_path = $tempdir . '/solr';
$solr_post_chunk_size = 100;
$sql_driver     = 'mysql';
$sql_host       = 'localhost';
$sql_db         = 'pdb';
$sql_port       = 3306;
$sql_user       = 'pdb';
$sql_pass       = '';

$keep_temporary     = 0;
$clear_db           = 1;
$disable_cvs        = 0;
$disable_indexing   = 0;
$disable_solr       = 0;
$disable_local_solr = 0;
$disable_delete     = 0;

$backend            = 'solr';

$debbaseurl         = 'http://bindist.finkmirrors.net/';

mkpath($tempdir . '/logs');

$ua = LWP::UserAgent->new();

# process command-line
GetOptions(
	'help'               => \$wanthelp,
	'xmldir=s'           => \$xmldir,
	'tempdir=s'          => \$tempdir,
	'verbose'            => \$debug,
	'trace'              => \$trace,
	'pause=i'            => \$pause,
	'start-at=s'         => \$start_at,
	'end-at=s'           => \$end_at,

	'url=s',             => \$solr_url,
	'port=s',            => \$solr_temp_port,

	'clear-db'           => \$clear_db,
	'keep-temporary'     => \$keep_temporary,
	'disable-cvs'        => \$disable_cvs,
	'disable-indexing'   => \$disable_indexing,
	'disable-solr'       => \$disable_solr,
	'disable-local-solr' => \$disable_local_solr,
	'disable-delete'     => \$disable_delete,

	'debbaseurl=s'       => \$debbaseurl,

	'backend=s'          => \$backend,

	'sqlhost=s'          => \$sql_host,
	'sqlport=i'          => \$sql_port,
	'sqldb=s'            => \$sql_db,
	'sqluser=s'          => \$sql_user,
	'sqlpass=s'          => \$sql_pass,
) or &die_with_usage;

$debug++ if ($trace);

&die_with_usage if $wanthelp;

if ($backend ne 'solr') {
	$disable_local_solr = 1;
	$disable_solr = 1;
}

if ($backend eq 'solr') {
	use WebService::Solr;

	$solr = WebService::Solr->new(
		$solr_url,
		{
			schema  => 'solr/solr/conf/schema.xml',
			port    => $solr_temp_port,
			url     => $solr_url,
			log_dir => $tempdir . '/logs',
		}
	);
}

if ($backend eq 'sql') {
	use DBI;

	my $dsn = "DBI:$sql_driver:database=$sql_db";
	if ($sql_host ne 'localhost') {
		$dsn .= ":$sql_host:$sql_port";
	}	

	$db = DBI->connect($dsn, $sql_user, $sql_pass) || die $DBI::errstr;
}

mkpath($tempdir);
open(LOCKFILE, '>>' . $tempdir . '/create-finkdb.lock') or die "could not open lockfile for append: $!";
if (not flock(LOCKFILE, LOCK_EX | LOCK_NB)) {
	die "Another process is running.";
}

# get the list of distributions to scan
{
	my $distributions;

	info("- parsing distribution/release information\n");

	open (GET_DISTRIBUTIONS, $topdir . '/get_distributions.php |') or die "unable to run $topdir/get_distributions.php: $!";
	my @keys = parse_csv(scalar(<GET_DISTRIBUTIONS>));

	while (my $line = <GET_DISTRIBUTIONS>)
	{
		my @values = parse_csv($line);
		my $entry = make_hash(\@keys, \@values);
		my $id = $entry->{'id'};
		$distributions->{$id} = $entry;
	}
	close(GET_DISTRIBUTIONS);

	open (GET_RELEASES, $topdir . '/get_distributions.php -r |') or die "unable to run $topdir/get_distributions.php -r: $!";
	@keys = parse_csv(scalar(<GET_RELEASES>));

	while (my $line = <GET_RELEASES>) 
	{
		my @values = parse_csv($line);
		my $entry = make_hash(\@keys, \@values);
		my $id = $entry->{'id'};

		debug("  - found $id\n");

		my $distribution_id = delete $entry->{'distribution_id'};
		$distributions->{$distribution_id} = {} unless (exists $distributions->{$distribution_id});
		$entry->{'distribution'} = $distributions->{$distribution_id};

		$releases->{$id} = $entry;
	}
	close (GET_RELEASES);
}

trace(Dumper($releases));

unless ($disable_local_solr) {
	my $proc = Proc::ProcessTable->new(enable_ttys => 0);
	for my $p (@{$proc->table}) {
		if ($p->cmndline =~ /fink.temporary.solr/) {
			info("- stopping old temporary solr(" . $p->pid . "): " . $p->cmndline);
			$p->kill(15);

			debug("  - waiting a few seconds for it to shut down");
			sleep(5);
		}
	}

	info("- syncing temporary solr instance\n");
	system('rsync', '-ar', '--exclude=*.log', '--exclude=work', '--exclude=index', '--exclude=CVS', '--delete-excluded', 'solr/', $solr_temp_path . '/') == 0 or die "unable to run rsync: $?";

	info("- starting temporary solr instance\n");
	$ENV{'SOLR_OPTS'} = "-Dfink.temporary.solr=1 -Djetty.port=$solr_temp_port";
	system($solr_temp_path . '/start.sh') == 0 or die "unable to start solr on port $solr_temp_port: $?";

	debug("  - waiting a few seconds for it to come up");
	sleep(5);
}

if ($backend eq 'sql') {
	create_sql_table();
}

my $started = 0;
$started = 1 if ($start_at eq '');
for my $release (reverse sort keys %$releases)
{
	if (not $started) {
		if ($release ne $start_at and $start_at ne "") {
			trace("- $release != $start_at\n");
			next;
		}
	}
	$started = 1;

	unless ($disable_cvs) {
		check_out_release($releases->{$release});
	}

	unless ($disable_indexing) {
		index_release($releases->{$release}, 0);
	}

	if ($clear_db) {
		delete_release($releases->{$release});
	}

	post_release($releases->{$release});

	if ($backend eq 'solr' and (not $disable_delete or not $clear_db)) {
		remove_obsolete_entries($releases->{$release});
	}

	if ($backend eq 'solr' and not $disable_solr) {
		commit_solr();
	}

	if ($release eq $end_at) {
		trace("- $release = $end_at\n");
		last;
	} else {
		if ($end_at ne "") {
			trace("- $release != $end_at\n");
		}
	}
}

optimize();

if ($backend eq 'solr' and not $disable_local_solr) {
	my $proc = Proc::ProcessTable->new(enable_ttys => 0);

	# first, kill the newly-indexed Solr
	unless ($keep_temporary) {
		for my $p (@{$proc->table}) {
			if ($p->cmndline =~ /fink.temporary.solr/) {
				info("- stopping temporary solr(" . $p->pid . "): " . $p->cmndline);
				$p->kill(15);
				debug("  - waiting a few seconds for it to shut down");
				sleep(5);
			}
		}
	}

	# next, kill the production Solr
	for my $p (@{$proc->table}) {
		if ($p->cmndline =~ /fink.production.solr/) {
			info("- stopping production solr(" . $p->pid . "): " . $p->cmndline . "\n");
			$p->kill(15);
			debug("  - waiting a few seconds for it to shut down");
			sleep(5);
		}
	}

	# copy the new indexes to the production instance
	info("- syncing indexes\n");
	system('rsync', '--delete', '-ar', '--exclude=CVS', $solr_temp_path . '/solr/data/', 'solr/solr/data/') == 0 or die "unable to sync solr data: $?";

	# start solr back up
	$ENV{'SOLR_OPTS'} = '-Dfink.production.solr=1';
	info("- starting production solr\n");
	system(getcwd() . '/solr/start.sh') == 0 or die "unable to start production solr: $?";
}

sub check_out_release
{
	my $release = shift;
	my $release_id = $release->{'id'};

	info("- checking out $release_id\n");

	my $tag = get_tag_name($release->{'version'});
	my $checkoutroot = get_basepath($release) . '/fink';
	my $basedir = $checkoutroot;

	my $exportdir    = "dists";

	my $workingdir   = $checkoutroot;
	my $cvsrep       = $checkoutroot . '/' . $exportdir . '/CVS/Repository';

	my @command = (
		'cvs',
		'-qz3',
		'-d', ':pserver:anonymous@fink.cvs.sourceforge.net:/cvsroot/fink',
		'checkout',
		'-PA',
		'-r', $tag,
		'-d', $exportdir,
		$release->{'distribution'}->{'rcspath'}
	);

	if (-e $cvsrep)
	{
		chomp(my $repo = read_file($cvsrep, binmode => ':utf8'));
		if ($repo eq $release->{'distribution'}->{'rcspath'})
		{
			@command = ( 'cvs', '-qz3', 'update', '-Pd', '-r', $tag );
			$workingdir .= '/' . $exportdir;
		} else {
			rmtree($checkoutroot . '/' . $exportdir);
		}
	}

	my $mydist = $release->{'type'};
	$mydist = 'stable' if ($mydist eq 'bindist');
	$mydist = 'unstable' if ($mydist eq 'bindist-unstable');

	# These chmods are terrible, Fink should be dealing with this internally
	# But this seems to be required for the time being
	my @mybranches = ('main', 'crypto');
	my ($mybranch, @sections, $section, $dirname);

	my $osx = $release->{'distribution'}->{'name'};

	foreach $mybranch (@mybranches) {
		system('chmod', '-f', '00750', $checkoutroot . '/' . $exportdir . '/unstable') if ($mydist eq 'stable');
		system('chmod', '-f', '00750', $checkoutroot . '/' . $exportdir . '/stable') if ($mydist eq 'unstable');
		$dirname = $checkoutroot . '/' . $exportdir . '/' . $mydist . '/'. $mybranch . '/finkinfo/';
		opendir my($dh), $dirname or next;
			@sections = grep { !/^\.\.?$/ } readdir $dh;
		closedir $dh;

		# Only continue is EOLs exists.
		next if (scalar(grep { /-EOL$/ } @sections) < 1);

		foreach $section (@sections) {
			if (-d $dirname . $section) {
				debug('  - unlocking ' . $dirname . $section);
				system('chmod', '-f', '00750',  $dirname . $section);
			}
		}
	}

	run_command($workingdir, @command);

	foreach $mybranch (@mybranches) {
		system('chmod', '-f', '00000', $checkoutroot . '/' . $exportdir . '/unstable') if ($mydist eq 'stable');
		system('chmod', '-f', '00000', $checkoutroot . '/' . $exportdir . '/stable') if ($mydist eq 'unstable');
		$dirname = $checkoutroot . '/' . $exportdir . '/' . $mydist . '/'. $mybranch . '/finkinfo/';
		opendir my($dh), $dirname or next;
			@sections = grep { !/^\.\.?$/ } readdir $dh;
		closedir $dh;

		# Only continue is EOLs exists.
		next if (scalar(grep { /-EOL$/ } @sections) < 1);
			debug("  - found EOLs in dir list\n");

		# Remove CVS dir
		@sections = grep { !/^CVS$/ } @sections;

		# Remove the current EOL or only EOL
		if (scalar(grep { /${osx}-EOL$/ } @sections) > 0) {
			debug("  - removing all but " . $osx . "-EOL from dir list\n");
			@sections = grep { !/^${osx}-EOL$/ } @sections;
		} else {
			debug("  - removing EOLs from dir list\n");
			@sections = grep { /-EOL$/ } @sections;
		}

		foreach $section (@sections) {
			if (-d $dirname . $section) {
				debug('  - locking ' . $dirname . $section);
				system('chmod', '-f', '00000',  $dirname . $section);
			}
		}
	}

	sleep($pause);
}

sub index_release
{
	my $release = shift;
	my $release_id = $release->{'id'};

	info("- indexing $release_id\n");

	$pkgs = {};
	my $post_immediately = shift || 0;

	my $tree = $release->{'type'};
	$tree = 'stable' if ($tree eq 'bindist');
	my $basepath = get_basepath($release);
	mkpath($basepath . '/var/lib/fink');

	my $xmlpath = get_xmlpath($release);
	if (-d $xmlpath) {
		rmtree($xmlpath);
	}
	mkpath($xmlpath);

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

	trace($release->{'id'} . " trees = " . $config->param("trees"), "\n");

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
	
		$desc = filter_description(
			$packageobj->param_default_expanded('DescDetail', '',
				expand_override => $expand_override,
				err_action => 'ignore'
			)
		);

		$usage = filter_description(
			$packageobj->param_default_expanded('DescUsage', '',
				expand_override => $expand_override,
				err_action => 'ignore'
			)
		);
	
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
		my $has_common_splitoffs = 'false';
		for my $splitoff ($packageobj->get_splitoffs())
		{
			if ($splitoff->get_name() =~ /-(shlibs|dev|bin|common|doc)$/) {
				$has_common_splitoffs = 'true';
			}
		}
		my $is_common_splitoff = 'false';
		if ($packageobj->get_name() =~ /-(shlibs|dev|bin|common|doc)$/) {
			$is_common_splitoff = 'true';
		}

		my (@depends, @builddepends);
		my ($depends, $builddepends) = ("", "");
		for my $entry (@{$packageobj->get_depends(0, 0)}) {
			for my $value (@{$entry}) {
				$value =~ s/\s*\([^\)]+\)\s*$//;
				push(@depends, $value);
			}
		}
		for my $entry (@{$packageobj->get_depends(1, 0)}) {
			for my $value (@{$entry}) {
				$value =~ s/\s*\([^\)]+\)\s*$//;
				push(@builddepends, $value);
			}
		}

		my $sort_value = Math::BigInt->bzero();
		{
			my $numeric_version = $packageobj->get_version();
			$numeric_version =~ s/[^0-9\.]//gs;
			my $multiplier = 1;
			for my $field (reverse(split(/\./, $numeric_version)))
			{
				my $value = Math::BigInt->bzero();
				$value->badd($field);
				$value->bmul($multiplier);
				$sort_value->badd($value);
				$multiplier *= 10000;
			}

			$sort_value = $sort_value->bstr();

			my $numeric_revision = $packageobj->get_revision();
			$numeric_revision =~ s/[^0-9]//gs;
			$numeric_revision =~ s/^.*(....)$/$1/ if (length($numeric_revision) > 4);
			$sort_value .= sprintf("%04d", $numeric_revision);
		}

		my $distpath = $release->{'distribution'}->{'rcspath'};

		my $debarchive = '';
		if ($release->{'type'} eq 'bindist' || $release->{'type'} eq 'bindist-unstable') {
			$debarchive = get_deb_archive($release, $packageobj->get_name(), $packageobj->get_version(), $packageobj->get_revision(), $packageobj->get_section());
			if ($debarchive eq '') {
				debug("  - skipping (deb not found) ".$packageobj->get_name()."\n");
				next;
			}

			debug("  - adding (deb found) ".$packageobj->get_name()."\n");
		}

		my $package_info = {
			name              => $packageobj->get_name(),
			sort_version      => $sort_value,
			version           => $packageobj->get_version(),
			revision          => $packageobj->get_revision(),
			epoch             => $packageobj->get_epoch() || '0',
			descshort         => $packageobj->get_shortdescription(),
			desclong          => $desc,
			descusage         => $usage,
			maintainer        => $maintainer,
			depends           => join(' ', sort(@depends)),
			builddepends      => join(' ', sort(@builddepends)),
			license           => $packageobj->get_license(),
			homepage          => $packageobj->param_default("Homepage", ""),
			section           => $packageobj->get_section(),
			parentname        => package_id($parent),
			infofile          => $infofile,
			debarchive        => $debarchive,
			
			rcspath           => $distpath . '/' . $infofile,
			tag               => get_tag_name($release->{'version'}),
			infofilechanged   => $infofilechanged,
			dist_id           => $release->{'distribution'}->{'id'},
			dist_name         => $release->{'distribution'}->{'name'},
			dist_architecture => $release->{'distribution'}->{'architecture'},
			dist_description  => $release->{'distribution'}->{'description'},
			dist_priority     => $release->{'distribution'}->{'priority'},
			dist_active       => $release->{'distribution'}->{'isactive'}? 'true':'false',
			dist_visible      => $release->{'distribution'}->{'isvisible'}? 'true':'false',
			dist_supported    => $release->{'distribution'}->{'issupported'}? 'true':'false',
			rel_id            => $release->{'id'},
			rel_type          => $release->{'type'},
			rel_version       => $release->{'version'},
			rel_priority      => $release->{'priority'},
			rel_active        => $release->{'isactive'}? 'true':'false',

			has_parent           => defined $parent? 'true' : 'false',
			has_common_splitoffs => $has_common_splitoffs,
			is_common_splitoff   => $is_common_splitoff,
		};

		debug("  - adding ", package_id($package_info));

		my $pkg_id = package_id($package_info);
		my $doc_id = $release->{'id'} . '-' . $pkg_id;

		if ($backend eq 'solr') {
			if ($post_immediately) {
				$solr->add( { $doc_id => $package_info } );
				return;
			}

			my $outputfile = $xmlpath . '/' . package_id($package_info) . '.xml';
			my $xml;

			my $writer = XML::Writer->new(OUTPUT => \$xml, UNSAFE => 1);

			# alternate schema, solr
			$writer->startTag("doc");

			$writer->cdataElement("field", $pkg_id, "name" => "pkg_id");
			$writer->cdataElement("field", $doc_id, "name" => "doc_id");

			for my $key (keys %$package_info)
			{
				if (exists $package_info->{$key} and defined $package_info->{$key})
				{
					$writer->cdataElement("field", $package_info->{$key}, "name" => $key);
				}
			}

			$writer->endTag("doc");

			$writer->end();

			write_file( $outputfile, {binmode => ':utf8'}, $xml );
		} elsif ($backend eq 'sql') {
			$pkgs->{$doc_id} = $package_info;
		}
	}
}

sub filter_description
{
	my $desc = shift;
	$desc =~ s/^(\s*\r?\n)+//gs;
	$desc =~ s/(\s*\r?\n)+$//gs;

	my @desc = split(/\r?\n/, $desc);
	my $spaces = 1000;
	# pass 1: figure out the least amount of spaces in the formatting
	for my $index (0..$#desc)
	{
		$desc[$index] =~ s/\t/        /gs;
		$desc[$index] =~ s/^\s*\.?\s*$//;

		if ($desc[$index] !~ /^\s*$/)
		{
			my ($whitespace) = $desc[$index] =~ /^(\s*)/;
			next unless (defined $whitespace);
			my $length = length($whitespace);
			if ($length < $spaces)
			{
				$spaces = $length;
			}
		}
	}
	if ($spaces > 0 and $spaces < 1000)
	{
		# pass 2: erase the minimum amount of spaces from each line
		for my $index (0..$#desc)
		{
			my $remove = " " x $spaces;
			$desc[$index] =~ s/^$remove//;
		}
	}
	return join("\n", @desc);
}

sub post_release_to_solr
{
	my $release = shift;
	my $release_id = $release->{'id'};

	my $xmlpath = get_xmlpath($release);

	my @files;

	find(
		{
			wanted => sub {
				return unless (/.xml$/);
				push(@files, $File::Find::name);
			},
			no_chdir => 1,
		},
		$xmlpath,
	);

	my $limit = $solr_post_chunk_size;
	while (@files) {
		my $count = 0;

		my $text = "<add>";
		while (++$count < $limit && @files) {
			$text .= read_file(shift(@files));
		}
		$text .= "</add>";

		do_post($text);
	}
}

sub remove_obsolete_entries
{
	my $release = shift;
	my $release_id = $release->{'id'};

	info("- removing obsolete $release_id files\n");

	my $xmlpath = get_xmlpath($release);
	my $basepath = get_basepath($release);
	find(
		{
			wanted => sub {
				return unless (/.xml$/);
				my $file = $_;

				trace("file = $file\n");

				my $contents = read_file($file, binmode => ':utf8');
				my ($doc_id)   = $contents =~ /<field name="doc_id">([^<]+)/;
				my ($name)     = $contents =~ /<field name="name">([^<]+)/;
				my ($infofile) = $contents =~ /<field name="infofile">([^<]+)/;

				return unless (defined($doc_id) and defined($infofile));

				my $infofilename = $basepath . '/fink/dists/' . $infofile;
				if (-f $infofilename)
				{
					trace("  - package $name is still valid ($infofile)\n");
				} else {
					debug("  - package $name is obsolete ($infofile)\n");
					do_post('<delete><query>+doc_id:"' . $doc_id . '"</query></delete>');
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
			trace("  - package ", $package->{'name'}, " is still valid (", $package->{'infofile'}, ")\n");
		} else {
			debug("  - package ", $package->{'name'}, " is obsolete (", $package->{'infofile'}, ")\n");
			do_post('<delete><query>+doc_id:"' . $package->{'doc_id'} . '"</query></delete>');
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
	trace("  - changing directory to $workingdir\n");
	chdir($workingdir);

	debug("  - running: @command\n");
	# Add a 15 minute timeout, keep the script from hanging.
	open(RUN, "/usr/bin/timeout 900 @command |") or die "unable to run @command: $!";
	while (<RUN>)
	{
		trace("  - " . $_);
	}
	close(RUN);

	trace("  - changing directory to $fromdir\n");
	chdir($fromdir);
}

# create a package ID from package information
# this needs to be kept in sync with web/pdb/finkinfo.inc
sub package_id
{
	my $package = shift;

	if (not $package) {
		return '';
	}

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

sub get_deb_archive
{
	my $release = shift;

	my $name = shift;
	my $version = shift;
	my $revision = shift;
	my $section = shift;

	my $dist = $release->{'type'};
	return '' unless ($dist eq 'bindist' || $dist eq 'bindist-unstable');
	$dist = 'stable' if ($dist eq 'bindist');
	$dist = 'unstable' if ($dist eq 'bindist-unstable');
	my $osx = $release->{'distribution'}->{'name'};
	my $arch = $release->{'distribution'}->{'architecture'};

	my $system = 'darwin';
	my $tree = 'main';

	if ($release->{'distribution'}->{'rcspath'} =~ /dists\/10.[0-9]\/$dist\/(.+)\/finkinfo\/.+\/.+\.info$/i) {
		$tree = $1;
	}

	my $debarchive = $osx.'/dists/'.$dist.'/'.$tree.'/binary-'.$system.'-'.$arch.'/'.$section.'/'.$name.'_'.$version.'-'.$revision.'_'.$system.'-'.$arch.'.deb';
	use LWP::Simple qw(head);
	if (head($debbaseurl.$debarchive)) {
		return $debarchive;
	}

	return '';
}

sub do_post
{
	my $data = shift;
	my $retries = 3;
	my $retval;
	my $failmsg = '';
	while ($retries-- > 0) {
		if ($backend eq 'solr') {
			$retval = post_to_solr($data);
		} elsif ($backend eq 'sql') {
			$retval = post_to_sql($data);
			if (not $retval) {
				$failmsg = " (" . $db->errstr . ")";
			}
		}
		if ($retval) {
			return 1
		}
	}

	die "failed to post after retries" . $failmsg;
}

sub post_to_sql
{
	my $data = shift;

	my $st = $db->prepare($data);
	$st->execute() || return 0;
	$st->finish();

	return 1;
}

sub post_to_solr
{
	my $data = shift;

	my $req = HTTP::Request->new(POST => $solr_url . '/update');
	$req->content_type('text/xml; charset=utf-8');

	# post the data
	if ($data !~ /\n/ and -f $data)
	{
		$req->content(scalar read_file($data, binmode => ':utf8'));
	} else {
		$req->content($data);
	}

	sleep($pause);

	my $response = $ua->request($req);
	if ($response->is_error())
	{
		info("failed to post update: " . $response->status_line() . "\n\nresponse content was:\n" . $response->content . "\n\nrequest content was:\n" . $req->content);
		return 0;
	}

	return 1;
}

sub delete_release
{
	my $release = shift;
	my $release_id = $release->{'id'};

	if ($backend eq 'solr' and $disable_solr) {
		return;
	}

	info("- deleting old data for $release_id\n");

	if ($backend eq 'solr') {
		delete_release_from_solr($release);
	} elsif ($backend eq 'sql') {
		delete_release_from_sql($release);
	}
}

sub post_release
{
	my $release = shift;
	my $release_id = $release->{'id'};

	info("- posting $release_id to $backend\n");

	if ($backend eq 'solr') {
		post_release_to_solr($release);
	} elsif ($backend eq 'sql') {
		post_release_to_sql($release);
	}
}

sub optimize_sql
{
	post_to_sql('OPTIMIZE TABLE `pdb`') || die "unable to optimize (" . $db->errstr . ")";
}

sub create_sql_table
{
	info("- checking table\n");

	post_to_sql("CREATE TABLE IF NOT EXISTS `pdb` (
 `name` varchar(60) NOT NULL,
 `sort_version` varchar(40) NOT NULL,
 `version` varchar(20) NOT NULL,
 `revision` varchar(20) NOT NULL,
 `epoch` smallint(6) DEFAULT NULL,
 `descshort` varchar(90) NOT NULL,
 `desclong` text,
 `descusage` text,
 `maintainer` varchar(250) NOT NULL,
 `depends` text,
 `builddepends` text,
 `license` varchar(40) DEFAULT NULL,
 `homepage` varchar(250) DEFAULT NULL,
 `section` varchar(40) NOT NULL,
 `parentname` varchar(60) DEFAULT NULL,
 `infofile` varchar(400) NOT NULL,
 `debarchive` varchar(400) DEFAULT NULL,
 `rcspath` varchar(400) NOT NULL,
 `tag` varchar(400) DEFAULT NULL,
 `infofilechanged` datetime NOT NULL,
 `dist_id` varchar(20) NOT NULL,
 `dist_name` varchar(20) NOT NULL,
 `dist_architecture` varchar(20) NOT NULL,
 `dist_description` varchar(80) NOT NULL,
 `dist_priority` smallint(6) NOT NULL,
 `dist_active` tinyint(4) NOT NULL DEFAULT '0',
 `dist_visible` tinyint(4) NOT NULL DEFAULT '0',
 `dist_supported` tinyint(4) NOT NULL DEFAULT '0',
 `rel_id` varchar(40) NOT NULL,
 `rel_type` varchar(20) NOT NULL,
 `rel_version` varchar(20) NOT NULL,
 `rel_priority` smallint(6) NOT NULL,
 `rel_active` tinyint(4) NOT NULL DEFAULT '0',
 `has_parent` tinyint(4) NOT NULL DEFAULT '0',
 `has_common_splitoffs` tinyint(4) NOT NULL DEFAULT '0',
 `is_common_splitoff` tinyint(4) NOT NULL DEFAULT '0',
 UNIQUE KEY `unique_pkg` (`rel_id`,`name`),
 KEY `arch` (`dist_architecture`),
 KEY `dist` (`dist_name`),
 KEY `maintainer` (`maintainer`),
 KEY `name` (`name`),
 KEY `section` (`section`),
 KEY `tree` (`rel_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8") || die "unable to create table (" . $db->errstr . ")";
}

sub delete_all_from_solr
{
	post_to_solr('<delete><query>*:*</query></delete>') || die "unable to run delete query";
}

sub delete_all_from_sql
{
	post_to_sql('DROP TABLE IF EXISTS `pdb`') || die "unable to run drop query (" . $db->errstr . ")";
}

sub delete_release_from_sql
{
	my $release = shift;
	my $release_id = $release->{'id'};

	my $text = "DELETE FROM `pdb` WHERE `rel_id` = '" . $release_id . "'";

	post_to_sql($text) || die "unable to run delete query (" . $db->errstr . ")";
}

sub post_release_to_sql
{
	my $release = shift;
	my $release_id = $release->{'id'};

	while (my ($key, $value) = each(%$pkgs)) {
		my $text = "INSERT INTO `pdb` SET
`name` = '" . $value->{'name'} . "',
`sort_version` = '" . $value->{'sort_version'} . "',
`version` = '" . $value->{'version'} . "',
`revision` = '" . $value->{'revision'} . "',
`epoch` = " . $value->{'epoch'} . ",
`descshort` = " . $db->quote($value->{'descshort'}) . ",
`desclong` = " . $db->quote($value->{'desclong'}) . ",
`descusage` = " . $db->quote($value->{'descusage'}) . ",
`maintainer` = " . $db->quote($value->{'maintainer'}) . ",
`depends` = '" . $value->{'depends'} . "',
`builddepends` = '" . $value->{'builddepends'} . "',
`license` = " . $db->quote($value->{'license'}) . ",
`homepage` = " . $db->quote($value->{'homepage'}) . ",
`section` = '" . $value->{'section'} . "',
`parentname` = '" . $value->{'parentname'} . "',
`infofile` = '" . $value->{'infofile'} . "',
`debarchive` = '" . $value->{'debarchive'} . "',
`rcspath` = '" . $value->{'rcspath'} . "',
`tag` = '" . $value->{'tag'} . "',
`infofilechanged` = '" . $value->{'infofilechanged'} . "',
`dist_id` = '" . $value->{'dist_id'} . "',
`dist_name` = '" . $value->{'dist_name'} . "',
`dist_architecture` = '" . $value->{'dist_architecture'} . "',
`dist_description` = '" . $value->{'dist_description'} . "',
`dist_priority` = " . $value->{'dist_priority'} . ",
`dist_active` = " . (($value->{'dist_active'}) ? 1 : 0) . ",
`dist_visible` = " . (($value->{'dist_visible'}) ? 1 : 0) . ",
`dist_supported` = " . (($value->{'dist_supported'}) ? 1 : 0) . ",
`rel_id` = '" . $value->{'rel_id'} . "',
`rel_type` = '" . $value->{'rel_type'} . "',
`rel_version` = '" . $value->{'rel_version'} . "',
`rel_priority` = " . $value->{'rel_priority'} . ",
`rel_active` = " . (($value->{'rel_active'}) ? 1 : 0) . ",
`has_parent` = " . (($value->{'has_parent'}) ? 1 : 0) . ",
`has_common_splitoffs` = " . (($value->{'has_common_splitoffs'}) ? 1 : 0) . ",
`is_common_splitoff` = " . (($value->{'is_common_splitoff'}) ? 1 : 0) . "
ON DUPLICATE KEY UPDATE
`sort_version` = '" . $value->{'sort_version'} . "',
`version` = '" . $value->{'version'} . "',
`revision` = '" . $value->{'revision'} . "',
`epoch` = " . $value->{'epoch'} . ",
`descshort` = " . $db->quote($value->{'descshort'}) . ",
`desclong` = " . $db->quote($value->{'desclong'}) . ",
`descusage` = " . $db->quote($value->{'descusage'}) . ",
`maintainer` = " . $db->quote($value->{'maintainer'}) . ",
`depends` = '" . $value->{'depends'} . "',
`builddepends` = '" . $value->{'builddepends'} . "',
`license` = " . $db->quote($value->{'license'}) . ",
`homepage` = " . $db->quote($value->{'homepage'}) . ",
`section` = '" . $value->{'section'} . "',
`parentname` = '" . $value->{'parentname'} . "',
`infofile` = '" . $value->{'infofile'} . "',
`debarchive` = '" . $value->{'debarchive'} . "',
`rcspath` = '" . $value->{'rcspath'} . "',
`tag` = '" . $value->{'tag'} . "',
`infofilechanged` = '" . $value->{'infofilechanged'} . "',
`dist_id` = '" . $value->{'dist_id'} . "',
`dist_name` = '" . $value->{'dist_name'} . "',
`dist_architecture` = '" . $value->{'dist_architecture'} . "',
`dist_description` = '" . $value->{'dist_description'} . "',
`dist_priority` = " . $value->{'dist_priority'} . ",
`dist_active` = " . (($value->{'dist_active'}) ? 1 : 0) . ",
`dist_visible` = " . (($value->{'dist_visible'}) ? 1 : 0) . ",
`dist_supported` = " . (($value->{'dist_supported'}) ? 1 : 0) . ",
`rel_type` = '" . $value->{'rel_type'} . "',
`rel_version` = '" . $value->{'rel_version'} . "',
`rel_priority` = " . $value->{'rel_priority'} . ",
`rel_active` = " . (($value->{'rel_active'}) ? 1 : 0) . ",
`has_parent` = " . (($value->{'has_parent'}) ? 1 : 0) . ",
`has_common_splitoffs` = " . (($value->{'has_common_splitoffs'}) ? 1 : 0) . ",
`is_common_splitoff` = " . (($value->{'is_common_splitoff'}) ? 1 : 0);

		do_post($text);
	}
}

sub optimize
{
	if ($backend eq 'solr') {
		optimize_solr();
	} elsif ($backend eq 'sql') {
		optimize_sql();
	}
}

sub optimize_solr
{
	unless ($disable_solr) {
		post_to_solr('<optimize/>') || die "unable to optimize";
	}
}

sub commit_solr
{
	post_to_solr('<commit/>') || die "unable to commit";
}

sub delete_release_from_solr
{
	my $release = shift;

	post_to_solr("<delete><query>+rel_id:$release->{'id'}</query></delete>") || die "unable to run delete query for $release";
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
	debug("  - get_packages_from_solr($query) found $num_docs documents\n");
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
	--help               this help
	--verbose            verbose output
	--trace              extremely verbose output

	--url=<path>         where SOLR's root is (default: http://127.0.0.1:1234/solr)
	--port=<port>        SOLR's port
	--tempdir=<path>     where to put temporary files
	--xmldir=<path>      where to write the .xml files

	--clear-db           delete existing index before doing anything
	--keep-temporary     keep the temporary solr instance running after import
	--disable-cvs        don't check out .info files
	--disable-indexing   don't index .info files to .xml files
	--disable-solr       don't post updated .xml files to solr
	--disable-local-solr don't use provided solr
	--disable-delete     don't delete outdated packages

	--debbaseurl=<url>   default base url to check for deb file, must
                             contain protocol and tailing slash.
                             (default: http://bindist.finkmirrors.net/)

	--pause=<seconds>    pause for X seconds between phases (default: 60)
	--start-at=<foo>     start at the given release ID
	--end-at=<foo>       end at the given release ID

	--backend=<dbtype>   type of backend (solr or sql, default: solr)

	--sqlhost=<host>     SQL host (default: localhost)
	--sqlpost=<port>     SQL port (default: 3306)
	--sqldb=<dbname>     SQL DB name (default: pdb)
	--sqluser=<user>     SQL user (default: pdb)
	--sqlpass=<pass>     SQL pass (default: none)

EOMSG
}

sub log_stdout
{
	my $line = join('', @_);
	chomp($line);
	my ($sec,$min,$hour,$mday,$mon,$year,$wday,$yday,$isdst)=localtime(time);
	print sprintf('%4d-%02d-%02d %02d:%02d:%02d', $year+1900,$mon+1,$mday,$hour,$min,$sec), ' ', $line, "\n";
}

sub info  { log_stdout(@_); }
sub debug { log_stdout(@_) if ($debug); }
sub trace { log_stdout(@_) if ($trace); }

# vim: ts=4 sw=4 noet
