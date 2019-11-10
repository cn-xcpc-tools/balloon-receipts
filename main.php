<?php declare(strict_types=1);
/**
 * Notify contest crew when there is a new, correct submission (for
 * which a balloon has to be handed out). Alternatively there's also
 * a web based tool in the jury interface.
 *
 * Part of the DOMjudge Programming Contest Jury System and licensed
 * under the GNU GPL. See README and COPYING for details.
 * 
 * Modify by cubercsl.
 */
if (isset($_SERVER['REMOTE_ADDR'])) {
    die("Commandline use only");
}

require('/home/ubuntu/domjudge/domserver/etc/domserver-static.php'); // change it to your domjudge etc
require(ETCDIR . '/domserver-config.php');

define('SCRIPT_ID', 'balloons');
define('LOGFILE', LOGDIR.'/balloons.log');

require(LIBDIR . '/init.php');

setup_database_connection();

require __DIR__ ."/vendor/autoload.php";
use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;

require_once 'Util.php';
$verbose = LOG_INFO;

$waittime = 5;

/**
 * Returns a text to be sent when notifying of a new balloon.
 */
function notification_text($team, $problem, $contest, $probs_solved, $probs_data, $comment)
{
    $ret =  "\n".     
        (empty($team['room']) ? "" : "位置: ".$team['room']."\n") .
        "比赛:".$contest['name']." (c".$contest['cid'].")\n".
        "队伍:".$team['name']." (t".$team['teamid'].")\n".
        "题目:".$probs_data[$problem]['shortname'].": ".$probs_data[$problem]['name'].
        (empty($probs_data[$problem]['color']) ? "" :
        " (".convertToColor($probs_data[$problem]['color']).")") . "\n\n" .
        "本队当前气球状态:\n";

    foreach ($probs_solved as $probid) {
        $ret .= " - " . $probs_data[$probid]['shortname'] .": " . $probs_data[$probid]['name'] .
        (empty($probs_data[$probid]['color']) ? "" :
        " (".convertToColor($probs_data[$probid]['color']).")") . "\n";
    }

    if ($comment) {
        $ret .= "\n$comment\n";
    }

    return $ret;
}

$cids = array();
$cdatas = array();
$nonfirst_contest = array();
$nonfirst_problem = array();
$nonfirst_team = array();
$infreeze = false;

logmsg(LOG_NOTICE, "Balloon notifications started [DOMjudge/".DOMJUDGE_VERSION."]");

initsignals();

// Constantly check database for new correct submissions
while (true) {

    // Check whether we have received an exit signal
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
    if ($exitsignalled) {
        logmsg(LOG_NOTICE, "Received signal, exiting.");
        exit;
    }

    $newcdatas = getCurContests(true);
    $newcids = array_keys($newcdatas);
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

    foreach ($cdatas as $cid => $cdata) {
        if (isset($cdata['freezetime']) && !$infreeze &&
            difftime(now(), (float)$cdata['freezetime']) >= 0) {
            $infreeze = true;
            logmsg(
                LOG_NOTICE,
                   "Scoreboard of contest c${cid} is frozen since " .
                   $cdata['freezetime']
            );
        }
        $freezecond = '';
        if (!dbconfig_get('show_balloons_postfreeze', 0) &&
            isset($cdata['freezetime'])) {
            $freezecond = 'AND submittime < "' . $cdata['freezetime'] . '"';
        }

        do {
            $res = $DB->q("SELECT b.*, s.probid, s.submittime,
                           t.teamid, t.name AS teamname, t.room, c.name AS catname
                           FROM balloon b
                           LEFT JOIN submission s USING (submitid)
                           LEFT JOIN team t USING (teamid)
                           LEFT JOIN team_category c USING (categoryid)
                           WHERE s.cid = %i AND b.printed = 0 AND c.visible = 1 $freezecond
                           ORDER BY submitid ASC", $cid);

            while ($row = $res->next()) {
                $team = array('name' => $row['teamname'],
                          'room' => $row['room'],
                          'teamid' => $row['teamid']);

                logmsg(LOG_DEBUG, "New problem solved: p" . $row['probid'] .
                                  " by team t" . $row['teamid'] .
                                  " for contest c" . $cid);

                // if (defined('BALLOON_CMD') && BALLOON_CMD) {
                    $probs_solved = $DB->q('COLUMN SELECT probid FROM scorecache
                                            WHERE cid = %i AND teamid = %i AND is_correct_restricted = 1',
                                           $cid, $row['teamid']);
                    $probs_data = $DB->q('KEYTABLE SELECT probid AS ARRAYKEY,shortname,name,color
                                          FROM problem LEFT JOIN contestproblem USING(probid) WHERE cid = %i', $cid);

                    // current limitation is that this gets reset if the balloon daemon is restarted
                    $comment = '';
                    if (!isset($nonfirst_contest[$cid])) {
                        $comment = '全场第一个通过题目';
                        $nonfirst_contest[$cid] = true;
                    } else {
                        if (!isset($nonfirst_problem[$cid]) || !isset($nonfirst_problem[$cid][$row['probid']])) {
                            $comment = '全场第一个通过此题';
                            $nonfirst_problem[$cid][$row['probid']] = true;
                        }
                        
                        if (!isset($nonfirst_team[$cid]) && !isset($nonfirst_team[$cid][$row['teamid']])) {
                            $comment = '队伍通过的第一道题';
                            $nonfirst_team[$cid][$row['teamid']] = true;
                        }
                        
                    }

                    logmsg(LOG_INFO, "Sending notification:" .
                           " team t" .$row['teamid'] .
                           ", problem p" . $row['probid'] .
                           ", contest c" . $cid . ".");

                    logmsg(LOG_DEBUG, "Running command: '" . BALLOON_CMD . "'");

                    
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
                            (empty($probs_data[$row['probid']]['color']) ? "" :
                            convertToColor($probs_data[$row['probid']]['color']))."\n"
                        );
                        $printer -> feed(1);

                        $printer -> setJustification();
                        $printer -> selectPrintMode();

                        $printer -> textChinese(notification_text(
                            $team,
                            $row['probid'],
                            $cdata,
                            $probs_solved,
                            $probs_data,
                            $comment
                        ));
                        
                        $printer -> text("--------------------------------\n");
                        $printer -> feed(3);          
                        
                    } catch (Exception $e) {
                        warning("Couldn't print to this printer: ". $e ->getMessage() . "\n");
                    } finally {
                        $DB->q('UPDATE balloon SET printed=1 WHERE balloonid = %i', $row['balloonid']);
                        $printer -> close();
                    }
                }
            // }
        } while ($res->count() != 0);
    }

    sleep($waittime);
}
