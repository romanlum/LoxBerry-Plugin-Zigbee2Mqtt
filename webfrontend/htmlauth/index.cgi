#!/usr/bin/perl

use LoxBerry::Web;
use CGI;

my $cgi = CGI->new;
my $q = $cgi->Vars;

my %pids;

my $template;

	
## Normal request (not ajax)

# Init template
$template = HTML::Template->new(
	filename => "$lbptemplatedir/main.html",
	global_vars => 1,
	loop_context_vars => 1,
	die_on_bad_params => 0,
);

$template->param("FORM_SETTINGS", 1);
print_form();

exit;

######################################################################
# Print Form
######################################################################
sub print_form
{
	my $plugintitle = "Zigbee2Mqtt" . LoxBerry::System::pluginversion();
	my $helplink = "https://link";
	my $helptemplate = "help.html";
	
	our %navbar;
	$navbar{10}{Name} = "Settings";
	$navbar{10}{URL} = 'index.cgi';
	$navbar{10}{active} = 1;
 
		
	LoxBerry::Web::lbheader($plugintitle, $helplink, $helptemplate);

	print $template->output();

	LoxBerry::Web::lbfooter();


}