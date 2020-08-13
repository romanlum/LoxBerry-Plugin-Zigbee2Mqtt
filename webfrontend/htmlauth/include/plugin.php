<?php
require_once "loxberry_web.php";
require_once "loxberry_system.php";
require_once LBPBINDIR . "/formHelper.php";
require_once LBPBINDIR . "/defines.php";
require_once '/usr/share/php/Twig/autoload.php';

/**
 * Plugin helper class
 */
class Plugin
{
    /**
     * Creates the page header
     * $L is globally available from defines.php
     * $navbar is globally available from loxberry
     */
    static function createHeader($activePage)
    {

        $template_title = "Zigbee2Mqtt Plugin";
        $helplink = "https://www.loxwiki.eu/";
        $helptemplate = "help.html";

        global $navbar;
        global $htmlhead;
        global $L;

        $navbar[1]['Name'] = $L["Navbar.Settings"];
        $navbar[1]['URL'] = 'index.php';
        $navbar[1]['Script'] = 'index.js';
        $navbar[1]['active'] = null;

        $navbar[2]['Name'] = $L["Navbar.Devices"];
        $navbar[2]['URL'] = 'devices.php';
        $navbar[2]['active'] = null;
        $navbar[2]['Script'] = array('vendor/ace.js', 'devices.js');

        $navbar[3]['Name'] = $L["Navbar.Map"];
        $navbar[3]['URL'] = 'map.php';
        $navbar[3]['active'] = null;
        $navbar[3]['Script'] = array('vendor/vis-network.min.js', 'map.js');

        $navbar[99]['Name'] = $L["Navbar.Logfiles"];
        $navbar[99]['URL'] = 'log.php';
        $navbar[99]['active'] = null;


        $navbar[$activePage]['active'] = true;
        $script = null;
        if (in_array('Script', $navbar[$activePage])) {
            $script = $navbar[$activePage]['Script'];
        }
        // this script is included in the loxberry header
        if ($script != null) {
            if (is_array($script)) {
                foreach ($script as $value) {
                    $htmlhead .= '<script src="js/' . $value . '"></script>';
                }
            } else {
                $htmlhead = '<script src="js/' . $script . '"></script>';
            }
        }

        // Creates the loxberry header
        LBWeb::lbheader($template_title, $helplink, $helptemplate);
    }

    /**
     * Initializes the plugin environment
     */
    static function initializeTwig()
    {
        global $lbptemplatedir;
        global $L;
        $loader = new \Twig\Loader\FilesystemLoader($lbptemplatedir);
        $twig = new \Twig\Environment($loader, [
            'cache' => "$lbptemplatedir/cache",
        ]);

        $filter = new \Twig\TwigFilter('trans', function ($string) use ($L) {
            return $L[$string];
        });
        $twig->addFilter($filter);
        return $twig;
    }
}
