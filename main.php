<?php declare(strict_types=1);
/**
 * @configure_input@
 *
 * Notify contest crew when there is a new, correct submission (for
 * which a balloon has to be handed out).
 *
 * This is only needed when not using the web based tool in the jury
 * interface of DOMjudge. This daemon and that tool cannot be used
 * at the same time.
 *
 * Usage:
 *  ./balloons https://example.org/domjudge
 * with your .netrc file containing the required credentials with at
 * least the 'balloon runner' role.
 *
 * Part of the DOMjudge Programming Contest Jury System and licensed
 * under the GNU GPL. See README and COPYING for details.
 * 
 * Modify by cubercsl.
 */
if (isset($_SERVER['REMOTE_ADDR'])) {
    die("Commandline use only");
}

define('SCRIPT_ID', 'balloons');
define('LOGFILE', 'balloons.log');

require_once('lib/lib.error.php');
require_once('lib/lib.misc.php');

$verbose = LOG_INFO;

define('DOMJUDGE_VERSION', '8.1.4');
define('WAITTIME', 5);

function usage() : void
{
    echo "Usage: " . SCRIPT_ID . " API_LOCATION\n" .
        "Send out notifications on each new balloon.\n\n" .
        "This script is not needed when using the web interface to handle balloons.\n\n" .
        "API_LOCATION is the base of your DOMjudge installation, without the\n" .
        "'/api' auffix and no trailing slash, e.g. https://example.org/domjudge\n\n" .
        "Credentials for this API need to be in a .netrc file in your home directory,\n" .
        "please refer to the DOMjudge manual for the format.\n\n";
        "The way to notify is configured inside this script by setting BALLOON_CMD.\n\n";
    exit;
}

require __DIR__ ."/vendor/autoload.php";
use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;

require_once 'Util.php';
/**
 * Returns a text to be sent when notifying of a new balloon.
 */
function notification_text(array $contest, string $teamname, ?string $location,
    string $problem, string $color, array $probs_solved, string $comment = null) : string
{
    $ret =  "\n".     
        "A problem has been solved:\n".
        "\n".
        (empty($location) ? "" : "位置: ".$location."\n") .
        "队伍:     ".$teamname."\n".
        "比赛:  ".$contest['name']." (c".$contest['id'].")\n".
        "题目:  ".$problem.
        (empty($color) ? "" : " (colour: ".$color.")") . "\n\n" .
        "本队当前气球状态:\n";

        foreach ($probs_solved as $probid => $color) {
            $ret .= " - " . $probid . " (colour: " . convertToColor($color['rgb']) . ")\n";
        }
    
        if ($comment) {
            $ret .= "\nNOTE: $comment\n";
        }

    return $ret;
}

function setup_curl_handle()
{
    $curl_handle = curl_init();
    curl_setopt($curl_handle, CURLOPT_USERAGENT, "balloons from DOMjudge/" . DOMJUDGE_VERSION);
    curl_setopt($curl_handle, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($curl_handle, CURLOPT_NETRC, true);
    curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, true);
    return $curl_handle;
}

function close_curl_handle($handle) : void
{
    curl_close($handle);
}

function request($curl_handle, string $API, string $url, string $verb = 'GET', string $data = '') : string
{
    $url = $API . "/" . $url;
    if ($verb == 'GET') {
        $url .= '?' . $data;
    }

    curl_setopt($curl_handle, CURLOPT_URL, $url);

    curl_setopt($curl_handle, CURLOPT_CUSTOMREQUEST, $verb);
    curl_setopt($curl_handle, CURLOPT_HTTPHEADER, []);
    if ($verb == 'POST') {
        curl_setopt($curl_handle, CURLOPT_POST, true);
        if (is_array($data)) {
            curl_setopt($curl_handle, CURLOPT_HTTPHEADER, ['Content-Type: multipart/form-data']);
        }
    } else {
        curl_setopt($curl_handle, CURLOPT_POST, false);
    }
    if ($verb == 'POST' || $verb == 'PUT') {
        curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $data);
    } else {
        curl_setopt($curl_handle, CURLOPT_POSTFIELDS, null);
    }

    $response = curl_exec($curl_handle);
    if ($response === false) {
        $errstr = "Error while executing curl $verb to url " . $url . ": " . curl_error($curl_handle);
        error($errstr);
    }
    $status = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
    if ($status < 200 || $status >= 300) {
        if ($status == 401) {
            $errstr = "Authentication failed (error $status) while contacting $url. " .
                "Check credentials in ~/.netrc.";
        } else {
            $errstr = "Error while executing curl $verb to url " . $url .
                ": http status code: " . $status .
                ", request size = " . strlen($data) .
                ", response: " . $response;
        }
        error($errstr);
    }

    return $response;
}

if ($argc <= 1) {
    usage();
    exit;
}

$API = $argv[1] . '/api/v4';

logmsg(LOG_NOTICE, "Balloon notifications started [DOMjudge/".DOMJUDGE_VERSION."]");

initsignals();

$curl_handle = setup_curl_handle();

$cids = [];
$cdatas = [];

// Constantly check database for new correct submissions
while (true) {

    // Check whether we have received an exit signal
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
    if ($exitsignalled) {
        logmsg(LOG_NOTICE, "Received signal, exiting.");
        close_curl_handle($curl_handle);
        exit;
    }

    $newcdatas = dj_json_decode(request($curl_handle, $API, 'contests', 'GET', 'onlyActive=true'));
    $newcids = [];
    foreach($newcdatas as $cdata) {
        $newcids[] = $cdata['id'];
    }
    $oldcids = $cids;
    $oldcidsstring = "none";
    if (!empty($oldcids)) {
        $oldcidsstring = implode(', ', array_map(function ($cid) {
            return 'c' . $cid;
        }, $oldcids));
    }
    $newcidsstring = "none";
    if (!empty($newcids)) {
        $newcidsstring = implode(', ', array_map(function ($cid) {
            return 'c' . $cid;
        }, $newcids));
    }
    if ($oldcids !== $newcids) {
        logmsg(LOG_NOTICE, "Contests has changed from " .
               $oldcidsstring . " to " .
               $newcidsstring);
        $cids = $newcids;
        $cdatas = $newcdatas;
    }

    foreach ($cdatas as $cdata) {
        $API_balloons = 'contests/' . urlencode($cdata['id']) . '/balloons';
        $rows = dj_json_decode(request($curl_handle, $API, $API_balloons, 'GET', 'todo=true'));
        foreach($rows as $row) {
            logmsg(LOG_DEBUG, "New problem solved: " . $row['problem'] .
                              " by team " . $row['team'] .
                              " for contest c" . $cdata['id']);

            logmsg(LOG_INFO, "Sending notification:" .
                   " team " . $row['team'] .
                   ", problem " . $row['problem'] .
                   ", contest c" . $cdata['id'] . ".");

            try {
                // Enter the device file for your USB printer here
                // $connector = new FilePrintConnector("php://stdout");
                $connector = new FilePrintConnector("/dev/usb/lp0");
                // $connector = new FilePrintConnector("/dev/usb/lp1");
                // $connector = new FilePrintConnector("/dev/usb/lp2");
                
                $printer = new Printer($connector);

                $printer ->initialize();

                $printer -> setJustification(Printer::JUSTIFY_CENTER);
                $printer -> selectPrintMode(Printer::MODE_DOUBLE_HEIGHT | Printer::MODE_DOUBLE_WIDTH);
                
                $printer -> textChinese("气球运输单\n");
                $printer -> feed(1);
                $printer -> textChinese("编号:".$row['balloonid']."\n");
                $printer -> feed(1);
                $printer -> textChinese(
                    (empty($team['room']) ? "" : $team['room'])." ".
                    convertToColor($row['contestproblem']['rgb'])."\n"
                );
                $printer -> feed(1);

                $printer -> setJustification();
                $printer -> selectPrintMode();

                $printer -> textChinese(notification_text(
                    $cdata,
                    $row['team'],
                    $row['location'],
                    $row['problem'],
                    convertToColor($row['contestproblem']['rgb']),
                    $row['total'],
                    $row['awards']
                ));
                
                $printer -> text("--------------------------------\n");
                $printer -> feed(3);   
            } catch (Exception $e) {
                warning("Couldn't print error: ". $e ->getMessage() . "\n");
            } finally {
                request($curl_handle, $API, $API_balloons . '/' . urlencode((string)$row['balloonid']) . '/done', 'POST');
                $printer -> close();
            }

        }
    }

    sleep(WAITTIME);
}
