<?php
namespace phpList;


class Language
{

    public $defaultlanguage = 'en';
    public $language = 'en';
    public $basedir = '';
    private $hasGettext = false;
    private $hasDB = false;
    private $_languages = array();
    private static $_instance;

    /**
     * @return array
     */
    public function getLanguages()
    {
        if (empty($this->_languages)) {
            ## pick up languages from the lan directory
            $landir = dirname(__FILE__) . '/locale/';
            $d = opendir($landir);
            while ($lancode = readdir($d)) {
                if (!in_array($landir, array_keys($this->_languages)) && is_dir($landir . '/' . $lancode) && is_file($landir . '/' . $lancode . '/language_info')) {
                    $lan_info = file_get_contents($landir . '/' . $lancode . '/language_info');
                    $lines = explode("\n", $lan_info);
                    $lan = array();
                    foreach ($lines as $line) {
                        // use utf8 matching
                        if (preg_match('/(\w+)=([\p{L}\p{N}&; \-\(\)]+)/u', $line, $regs)) {
                            #      if (preg_match('/(\w+)=([\w&; \-\(\)]+)/',$line,$regs)) {
                            #      if (preg_match('/(\w+)=(.+)/',$line,$regs)) {
                            $lan[$regs[1]] = $regs[2];
                        }
                    }
                    if (!isset($lan['gettext'])) $lan['gettext'] = $lancode;
                    if (!empty($lan['name']) && !empty($lan['charset'])) {
                        $this->_languages[$lancode] = array($lan['name'], $lan['charset'], $lan['charset'], $lan['gettext']);
                    }
                }
            }

            ## pick up other languages from DB
            if (Sql_table_exists('i18n')) {
                $req = Sql_Query(sprintf('SELECT lan,translation FROM %s WHERE
                                              original = "language-name" AND lan NOT IN ("%s")',
                    Config::getTableName('i18n'),
                    join('","', array_keys($this->_languages))));
                while ($row = Sql_Fetch_Assoc($req)) {
                    $this->_languages[$row['lan']] = array($row['translation'], 'UTF-8', 'UTF-8', $row['lan']);
                }
            }
            uasort($this->_languages, "lanSort");
        }
        return $this->_languages;
    }

    private static function lanSort($a, $b)
    {
        return strcmp(strtolower($a[0]), strtolower($b[0]));
    }

    private function __construct()
    {
        $this->basedir = dirname(__FILE__) . '/locale/';
        $this->defaultlanguage = Config::DEFAULT_SYSTEM_LANGUAGE;
        $this->language = Config::DEFAULT_SYSTEM_LANGUAGE;

        $languages = $this->getLanguages();
        if (isset($_SESSION['adminlanguage']) && isset($languages[$_SESSION['adminlanguage']['iso']])) {
            $this->language = $_SESSION['adminlanguage']['iso'];
        } else {
            unset($_SESSION['adminlanguage']);
        }
        if (function_exists('gettext')) {
            $this->hasGettext = true;
        }
        if (isset($_SESSION['hasI18Ntable'])) {
            $this->hasDB = $_SESSION['hasI18Ntable'];
        } elseif (Sql_Check_For_Table('i18n')) {
            $_SESSION['hasI18Ntable'] = true;
            $this->hasDB = true;
        } else {
            $_SESSION['hasI18Ntable'] = false;
        }

        /*
        $lan = array();

        if (is_file($this->basedir . $this->language . '/' . $page . '.php')) {
            @include $this->basedir . $this->language . '/' . $page . '.php';
        } elseif (!isset($GLOBALS['developer_email'])) {
            @include $this->basedir . $this->defaultlanguage . '/' . $page . '.php';
        }
        $this->lan = $lan;
        $lan = array();

        if (is_file($this->basedir . $this->language . '/common.php')) {
            @include $this->basedir . $this->language . '/common.php';
        } elseif (!isset($GLOBALS['developer_email'])) {
            @include $this->basedir . $this->defaultlanguage . '/common.php';
        }
        $this->lan += $lan;
        $lan = array();

        if (is_file($this->basedir . $this->language . '/frontend.php')) {
            @include $this->basedir . $this->language . '/frontend.php';
        } elseif (!isset($GLOBALS['developer_email'])) {
            @include $this->basedir . $this->defaultlanguage . '/frontend.php';
        }
        $this->lan += $lan;
        */
    }

    public static function Instance(){
        if (!Language::$_instance instanceof self) {
            Language::$_instance = new self();
        }
        return Language::$_instance;
    }

    function gettext($text)
    {
        bindtextdomain('phplist', './locale');
        textdomain('phplist');

        /* gettext is a bit messy, at least on my Ubuntu 10.10 machine
         *
         * if eg language is "nl" it won't find it. It'll need to be "nl_NL";
         * also the Ubuntu system needs to have the language installed, even if phpList has it
         * it won't find it, if it's not on the system
         *
         * So, to e.g. get "nl" gettext support in phpList (on ubuntu, but presumably other linuxes), you'd have to do
         * cd /usr/share/locales
         * ./install-language-pack nl_NL
         * dpkg-reconfigure locales
         *
         * but when you use "nl_NL", the language .mo can still be in "nl".
         * However, it needs "nl/LC_MESSAGES/phplist.mo s, put a symlink LC_MESSAGES to itself
         *
         * the "utf-8" strangely enough needs to be added but can be spelled all kinds
         * of ways, eg "UTF8", "utf-8"
         *
         *
         * AND then of course the lovely Accept-Language vs gettext
         * https://bugs.php.net/bug.php?id=25051
         *
         * Accept-Language is lowercase and with - and gettext is country uppercase and with underscore
         *
         * More ppl have come across that: http://grep.be/articles/php-accept
         *
        */

        ## so, to get the mapping from "nl" to "nl_NL", use a gettext map in the related directory
        if (is_file(dirname(__FILE__) . '/locale/' . $this->language . '/gettext_code')) {
            $lan_map = file_get_contents(dirname(__FILE__) . '/locale/' . $this->language . '/gettext_code');
            $lan_map = trim($lan_map);
        } else {
            ## try to do "fr_FR", or "de_DE", might work in most cases
            ## hmm, not for eg fa_IR or zh_CN so they'll need the above file
            # http://www.gnu.org/software/gettext/manual/gettext.html#Language-Codes
            $lan_map = $this->language . '_' . strtoupper($this->language);
        }

        putenv("LANGUAGE=" . $lan_map . '.utf-8');
        setlocale(LC_ALL, $lan_map . '.utf-8');
        bind_textdomain_codeset('phplist', 'UTF-8');
        $gt = gettext($text);
        return ($gt && $gt != $text) ? $gt : '';
    }

    function databaseTranslation($text)
    {
        if (!$this->hasDB) return '';
        $tr = Sql_Fetch_Row_Query(sprintf('select translation from ' . $GLOBALS['tables']['i18n'] . ' where original = "%s" and lan = "%s"',
            sql_escape(trim($text)), $this->language), 1);
        if (empty($tr[0])) {
            $tr = Sql_Fetch_Row_Query(sprintf('select translation from ' . $GLOBALS['tables']['i18n'] . ' where original = "%s" and lan = "%s"',
                sql_escape($text), $this->language), 1);
        }
        if (empty($tr[0])) {
            $tr = Sql_Fetch_Row_Query(sprintf('select translation from ' . $GLOBALS['tables']['i18n'] . ' where original = "%s" and lan = "%s"',
                sql_escape(str_replace('"', '\"', $text)), $this->language), 1);
        }
        return stripslashes($tr[0]);
    }

    function pageTitle($page)
    {
        ## try gettext and otherwise continue
        if ($this->hasGettext) {
            $gettext = $this->gettext($page);
            if (!empty($gettext)) {
                return $gettext;
            }
        }
        $page_title = '';
        $dbTitle = $this->databaseTranslation('pagetitle:' . $page);
        if ($dbTitle) {
            $page_title = $dbTitle;
        } elseif (is_file(dirname(__FILE__) . '/locale/' . $this->language . '/pagetitles.php')) {
            include dirname(__FILE__) . '/locale/' . $this->language . '/pagetitles.php';
        } elseif (is_file(dirname(__FILE__) . '/lan/' . $this->language . '/pagetitles.php')) {
            include dirname(__FILE__) . '/lan/' . $this->language . '/pagetitles.php';
        }
        if (preg_match('/pi=([\w]+)/', $page, $regs)) {
            ## @@TODO call plugin to ask for title
            if (isset($GLOBALS['plugins'][$regs[1]])) {
                $title = $GLOBALS['plugins'][$regs[1]]->pageTitle($page);
            } else {
                $title = $regs[1] . ' - ' . $page;
            }

        } elseif (!empty($page_title)) {
            $title = $page_title;
        } else {
            $title = $page;
        }
        return $title;
    }

    function pageTitleHover($page)
    {
        $hoverText = '';
        $dbTitle = $this->databaseTranslation('pagetitlehover:' . $page);
        if ($dbTitle) {
            $hoverText = $dbTitle;
        } else {
            $hoverText = $this->pageTitle($page);
            ## is this returns itself, wipe it, so the linktext is used instead
            if ($hoverText == $page) {
                $hoverText = '';
            }
        }
        if (!empty($hoverText)) {
            return $hoverText;
        }
        return '';
    }

    function formatText($text)
    {
        # we've decided to spell phplist with uc L
        $text = str_ireplace('phplist', 'phpList', $text);

        if (isset($GLOBALS["developer_email"])) {
            if (!empty($_SESSION['show_translation_colours'])) {
                return '<span style="color:#A704FF">' . str_replace("\n", "", $text) . '</span>';
            }
#       return 'TE'.$text.'XT';
        }
#    return '<span class="translateabletext">'.str_replace("\n","",$text).'</span>';
        return str_replace("\n", "", $text);
    }

    /**
     * obsolete
     */

    function missingText($text)
    {
        if (isset($GLOBALS["developer_email"])) {
            if (isset($_GET['page'])) {
                $page = $_GET["page"];
            } else {
                $page = 'home';
            }
            $pl = $prefix = '';
            if (!empty($_GET['pi'])) {
                $pl = $_GET['pi'];
                $pl = preg_replace('/\W/', '', $pl);
                $prefix = $pl . '_';
            }

            $msg = '

      Undefined text reference in page ' . $page . '

      ' . $text;

            $page = preg_replace('/\W/', '', $page);

            #sendMail($GLOBALS["developer_email"],"phplist dev, missing text",$msg);
            $line = "'" . str_replace("'", "\'", $text) . "' => '" . str_replace("'", "\'", $text) . "',";
#      if (is_file($this->basedir.'/en/'.$page.'.php') && $_SESSION['adminlanguage']['iso'] == 'en') {
            if (empty($prefix) && $_SESSION['adminlanguage']['iso'] == 'en') {
                $this->appendText($this->basedir . '/en/' . $page . '.php', $line);
            } else {
                $this->appendText('/tmp/' . $prefix . $page . '.php', $line);
            }

            if (!empty($_SESSION['show_translation_colours'])) {
                return '<span style="color: #FF1717">' . $text . '</span>'; #MISSING TEXT
            }
        }
        return $text;
    }

    function appendText($file, $text)
    {
        return;
        $filecontents = '';
        if (is_file($file)) {
            $filecontents = file_get_contents($file);
        } else {
            $filecontents = '<?php

$lan = array(

);

      ?>';
        }

#    print "<br/>Writing $text to $file";
        $filecontents = preg_replace("/\n/", "@@NL@@", $filecontents);
        $filecontents = str_replace(');', '  ' . $text . "\n);", $filecontents);
        $filecontents = str_replace("@@NL@@", "\n", $filecontents);

        $dir = dirname($file);
        if (!is_writable($dir) || (is_file($file) && !is_writable($file))) {
            $newfile = basename($file);
            $file = '/tmp/' . $newfile;
        }

        file_put_contents($file, $filecontents);
    }

    function getPluginBasedir()
    {
        $pl = $_GET['pi'];
        $pl = preg_replace('/\W/', '', $pl);
        $pluginroot = '';
        if (isset($GLOBALS['plugins'][$pl]) && is_object($GLOBALS['plugins'][$pl])) {
            $pluginroot = $GLOBALS['plugins'][$pl]->coderoot;
        }
        if (is_dir($pluginroot . '/lan/')) {
            return $pluginroot . '/lan/';
        } else {
            return $pluginroot . '/';
        }
    }

    function initFSTranslations($language = '')
    {
        if (empty($language)) {
            $language = $this->language;
        }
        $translations = parsePO(file_get_contents(dirname(__FILE__) . '/locale/' . $language . '/phplist.po'));
        $time = filemtime(dirname(__FILE__) . '/locale/' . $language . '/phplist.po');
        $this->updateDBtranslations($translations, $time, $language);
    }

    function updateDBtranslations($translations, $time, $language = '')
    {
        if (empty($language)) {
            $language = $this->language;
        }
        if (sizeof($translations)) {
            foreach ($translations as $orig => $trans) {
                Sql_Replace($GLOBALS['tables']['i18n'], array('lan' => $language, 'original' => $orig, 'translation' => $trans), '');
            }
        }
        saveConfig('lastlanguageupdate-' . $language, $time, 0);
    }

    function getTranslation($text, $page, $basedir)
    {

        ## try DB, as it will be the latest
        if ($this->hasDB) {
            $db_trans = $this->databaseTranslation($text);
            if (!empty($db_trans)) {
                return $this->formatText($db_trans);
            } elseif (is_file(dirname(__FILE__) . '/locale/' . $this->language . '/phplist.po')) {
                if (function_exists('getConfig')) {
                    $lastUpdate = getConfig('lastlanguageupdate-' . $this->language);
                    $thisUpdate = filemtime(dirname(__FILE__) . '/locale/' . $this->language . '/phplist.po');
                    if ($thisUpdate > $lastUpdate && !empty($_SESSION['adminloggedin'])) {
                        ## we can't translate this, as it'll be recursive
                        $GLOBALS['pagefooter']['transupdate'] = '<script type="text/javascript">initialiseTranslation("Initialising phpList in your language, please wait.");</script>';
                    }
                }
                #$this->updateDBtranslations($translations,$time);
            }
        }

        ## next try gettext, although before that works, it requires loads of setting up
        ## but who knows
        if ($this->hasGettext) {
            $gettext = $this->gettext($text);
            if (!empty($gettext)) {
                return $this->formatText($gettext);
            }
        }

        $lan = $this->lan;

        if (trim($text) == "") return "";
        if (strip_tags($text) == "") return $text;
        if (isset($lan[$text])) {
            return $this->formatText($lan[$text]);
        }
        if (isset($lan[strtolower($text)])) {
            return $this->formatText($lan[strtolower($text)]);
        }
        if (isset($lan[strtoupper($text)])) {
            return $this->formatText($lan[strtoupper($text)]);
        }

        return '';
    }


    function get($text)
    {
        if (trim($text) == "") return "";
        if (strip_tags($text) == "") return $text;
        $translation = '';

        $this->basedir = dirname(__FILE__) . '/lan/';
        if (isset($_GET['origpage']) && !empty($_GET['ajaxed'])) { ## used in ajaxed requests
            $page = basename($_GET["origpage"]);
        } elseif (isset($_GET["page"])) {
            $page = basename($_GET["page"]);
        } else {
            $page = "home";
        }
        $page = preg_replace('/\W/', '', $page);

        if (!empty($_GET['pi'])) {
            $plugin_languagedir = $this->getPluginBasedir();
            if (is_dir($plugin_languagedir)) {
                $translation = $this->getTranslation($text, $page, $plugin_languagedir);
            }
        }

        ## if a plugin did not return the translation, find it in core
        if (empty($translation)) {
            $translation = $this->getTranslation($text, $page, $this->basedir);
        }

        #   print $this->language.' '.$text.' '.$translation. '<br/>';

        # spelling mistake, retry with old spelling
        if ($text == 'over threshold, user marked unconfirmed' && empty($translation)) {
            return $this->get('over treshold, user marked unconfirmed');
        }

        if (!empty($translation)) {
            return $translation;
        } else {
            return $this->missingText($text);
        }
    }
}

/* add a shortcut that seems common in other apps
 * function s($text)
 * @param $text string the text to find
 * @params 2-n variable - parameters to pass on to the sprintf of the text
 * @return translated text with parameters filled in
 *
 *
 * eg s("This is a %s with a %d and a %0.2f","text",6,1.98765);
 *
 * will look for the translation of the string and substitute the parameters
 *
 **/

function s($text) {
    ## allow overloading with sprintf paramaters
    $translation = Language::Instance()->get($text);

    if (func_num_args() > 1) {
        $args = func_get_args();
        array_shift($args);
        $translation = vsprintf($translation, $args);
    }
    return $translation;
}

/**
 * function snbr
 * similar to function s, but without overloading params
 * will return the translated text with spaces turned to &nbsp; so that they won't wrap
 * mostly useful for buttons
 */
function snbr($text) {
    $trans = s($text);
    $trans = str_replace(' ','&nbsp;',$trans);
    return $trans;
}