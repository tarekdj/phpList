<?php
/**
 * User: SaWey
 * Date: 5/12/13
 */

namespace phpList;


//Include core files
include(__DIR__ .'/helper/Cache.php');
include(__DIR__ .'/helper/DefaultConfig.php');
include(__DIR__ .'/helper/IDatabase.php');
include(__DIR__ .'/helper/Language.php');
include(__DIR__ .'/helper/Logger.php');
include(__DIR__ .'/helper/MySQLi.php');
include(__DIR__ .'/helper/PrepareMessage.php');
include(__DIR__ .'/helper/Process.php');
include(__DIR__ .'/helper/String.php');
include(__DIR__ .'/helper/Timer.php');
include(__DIR__ .'/helper/Util.php');
include(__DIR__ .'/helper/Validation.php');
include(__DIR__ .'/Admin.php');
include(__DIR__ .'/Attachment.php');
include(__DIR__ .'/UserConfig.php');
include(__DIR__ .'/Config.php');
include(__DIR__ .'/MailingList.php');
include(__DIR__ .'/Message.php');
include(__DIR__ .'/MessageQueue.php');
include(__DIR__ .'/Output.php');
include(__DIR__ .'/phpListMailer.php');
include(__DIR__ .'/Session.php');
include(__DIR__ .'/Template.php');
include(__DIR__ .'/User.php');
Config::start();

class phpList
{
    /**
     * @return IDatabase
     * @throws \Exception
     */
    public static function DB()
    {
        switch (Config::DATABASE_MODULE) {
            case 'mysqli':
                return MySQLi::instance();
                break;
            default:
                throw new \Exception("DB Module not available");
        }
    }

    /**
     * @throws \Exception
     */
    public static function initialize()
    {
        //Timer replaces $GLOBALS['pagestats']['time_start']
        //DB->getQueryCount replaces $GLOBALS['pagestats']['number_of_queries']
        Timer::start('pagestats');

        $configfile = '';
        if (isset($_SERVER['ConfigFile']) && is_file($_SERVER['ConfigFile'])) {
            $configfile = $_SERVER['ConfigFile'];
        } elseif (isset($cline['c']) && is_file($cline['c'])) {
            $configfile = $cline['c'];
        } else {
            $configfile = 'UserConfig.php';
        }

        if (is_file($configfile) && filesize($configfile) > 20) {
            include $configfile;
        } elseif (PHP_SAPI == 'cli') {
            throw new \Exception('Cannot find config file');
        } else {
            Config::setRunningConfig('installer', true);
            return;
        }

        error_reporting(0);
        //check for commandline and cli version
        if (!isset($_SERVER['SERVER_NAME']) && PHP_SAPI != 'cli') {
            throw new \Exception('Warning: commandline only works well with the cli version of PHP');
        }
        if (isset($_REQUEST['_SERVER'])) { return; }
        $cline = array();
        Config::setRunningConfig('commandline', false);

        Util::unregister_GLOBALS();
        Util::magicQuotes();

        # setup commandline
        if (php_sapi_name() == 'cli') {
            for ($i=0; $i<$_SERVER['argc']; $i++) {
                $my_args = array();
                if (preg_match('/(.*)=(.*)/',$_SERVER['argv'][$i], $my_args)) {
                    $_GET[$my_args[1]] = $my_args[2];
                    $_REQUEST[$my_args[1]] = $my_args[2];
                }
            }
            Config::setRunningConfig('commandline', true);
            $cline = Util::parseCLine();
            $dir = dirname($_SERVER['SCRIPT_FILENAME']);
            chdir($dir);

            if (!is_file($cline['c'])) {
                throw new \Exception('Cannot find config file');
            }

        } else {
            Config::setRunningConfig('commandline', false);
        }
        Config::setRunningConfig('ajax', isset($_GET['ajaxed']));


        ## this needs more testing, and docs on how to set the Timezones in the DB
        if (Config::USE_CUSTOM_TIMEZONE) {
            #  print('set time_zone = "'.SYSTEM_TIMEZONE.'"<br/>');
            phpList::DB()->query('SET time_zone = "'.Config::SYSTEM_TIMEZONE.'"');
            ## verify that it applied correctly
            $tz = phpList::DB()->fetchRowQuery('SELECT @@session.time_zone');
            if ($tz[0] != Config::SYSTEM_TIMEZONE) {
                throw new \Exception('Error setting timezone in Sql Database');
            } else {
            #    print "Mysql timezone set to $tz[0]<br/>";
            }
            $phptz_set = date_default_timezone_set(Config::SYSTEM_TIMEZONE);
            $phptz = date_default_timezone_get ();
            if (!$phptz_set || $phptz != Config::SYSTEM_TIMEZONE) {
                ## I18N doesn't exist yet, @@TODO need better error catching here
                throw new \Exception('Error setting timezone in PHP');
            } else {
            #    print "PHP system timezone set to $phptz<br/>";
            }
            #  print "Time now: ".date('Y-m-d H:i:s').'<br/>';
        }

        if( !Config::DEBUG ) {
            ini_set('error_append_string',
                'phpList version '.Config::get('VERSION')
            );
            ini_set(
                'error_prepend_string',
                '<p class="error">Sorry a software error occurred:<br/>
                Please <a href="http://mantis.phplist.com">report a bug</a> when reporting the bug, please include URL and the entire content of this page.<br/>'
            );
        }


        //TODO: we probably will need >5.3
        if (version_compare(PHP_VERSION, '5.1.2', '<') && WARN_ABOUT_PHP_SETTINGS) {
            throw new \Exception(s('phpList requires PHP version 5.1.2 or higher'));
        }

        if (Config::ALLOW_ATTACHMENTS
            && Config::WARN_ABOUT_PHP_SETTINGS
            && (!is_dir(Config::ATTACHMENT_REPOSITORY)
                || !is_writable (Config::ATTACHMENT_REPOSITORY)
            )
        ) {
            $tmperror = '';
            if(ini_get('open_basedir')) {
                $tmperror = s('open_basedir restrictions are in effect, which may be the cause of the next warning: ');
            }
            $tmperror .= s('The attachment repository does not exist or is not writable');
            throw new \Exception($tmperror);
        }

        $this_doc = getenv('REQUEST_URI');
        if (preg_match('#(.*?)/admin?$#i',$this_doc,$regs)) {
            $check_pageroot = Config::PAGEROOT;
            $check_pageroot = preg_replace('#/$#','',$check_pageroot);
            if ($check_pageroot != $regs[1] && Config::WARN_ABOUT_PHP_SETTINGS)
                throw new \Exception(s('The pageroot in your config does not match the current locationCheck your config file.'));
        }
    }

    /**
     * Function to end current round of statistics gathering and write to log if required
     */
    public static function endStatistics()
    {
        /*
        print "\n\n".'<!--';
        if (Config::DEBUG) {
            print '<br clear="all" />';
            print phpList::DB()->getQueryCount().' db queries in $elapsed seconds';
            if (function_exists('memory_get_peak_usage')) {
                $memory_usage = 'Peak: ' .memory_get_peak_usage();
            } elseif (function_exists('memory_get_usage')) {
                $memory_usage = memory_get_usage();
            } else {
                $memory_usage = 'Cannot determine with this PHP version';
            }
            print '<br/>Memory usage: '.$memory_usage;
        }*/

        if (Config::get('statslog', false) !== false) {
            if ($fp = @fopen(Config::get('statslog'),'a')) {
                @fwrite(
                    $fp,
                    phpList::DB()->getQueryCount()."\t".
                    Timer::get('pagestats')->elapsed()."\t".
                    $_SERVER['REQUEST_URI']."\t".
                    Config::get('installation_name')."\n"
                );
            }
        }
    }

    /**
     * Send all headers in $headers to the browser
     */
    public static function sendHeaders()
    {
        if(!empty(phpList::$_instance->headers)){
            foreach(phpList::$_instance->headers as $header){
                header($header);
            }
            phpList::$_instance->headers = array();
        }
    }

} 