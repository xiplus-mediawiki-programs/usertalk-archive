<?php
require __DIR__ . "/../config/config.php";
if (!in_array(PHP_SAPI, $C["allowsapi"])) {
	exit("No permission");
}

set_time_limit(600);
date_default_timezone_set('UTC');
$starttime = microtime(true);
@include __DIR__ . "/config.php";
require __DIR__ . "/../function/curl.php";
require __DIR__ . "/../function/login.php";
require __DIR__ . "/../function/edittoken.php";

function converttime($chitime)
{
	if (preg_match("/(\d{4})年(\d{1,2})月(\d{1,2})日 \(.{3}\) (\d{2})\:(\d{2}) \(UTC\)/", $chitime, $m)) {
		return strtotime($m[1] . "/" . $m[2] . "/" . $m[3] . " " . $m[4] . ":" . $m[5]);
	} else {
		echo "converttime fail\n";
		exit(0);
	}
}

echo "The time now is " . date("Y-m-d H:i:s") . " (UTC)\n";

$config_page = file_get_contents($C["config_page"]);
if ($config_page === false) {
	echo "get config failed\n";
	exit(0);
}
$cfg = json_decode($config_page, true);

if (!$cfg["enable"]) {
	echo "disabled\n";
	exit(0);
}

login("bot");
$edittoken = edittoken();

echo "archive before " . $cfg['retention_time'] . " ago (" . date("Y-m-d H:i:s", time() - $cfg['retention_time']) . ")\n";

$to_page_name = sprintf(
	$cfg["archive_page_name"],
	date("Y"),
	(date("n") <= 6 ? 1 : 2)
);
echo "archive to " . $to_page_name . "\n";

for ($i = $C["fail_retry"]; $i > 0; $i--) {
	$starttimestamp = time();
	$res = cURL($C["wikiapi"] . "?" . http_build_query(array(
		"action" => "query",
		"prop" => "revisions",
		"format" => "json",
		"rvprop" => "content|timestamp",
		"titles" => $C["from_page"],
	)));
	if ($res === false) {
		echo "fetch page fail\n";
		exit(0);
	}
	$res = json_decode($res, true);
	$pages = current($res["query"]["pages"]);
	$text = $pages["revisions"][0]["*"];
	$basetimestamp = $pages["revisions"][0]["timestamp"];
	echo "get main page\n";

	$hash = md5(uniqid(rand(), true));
	$text = preg_replace("/^( *==.+?== *)$/m", $hash . "$1", $text);
	$text = explode($hash, $text);
	echo "find " . count($text) . " sections\n";

	$oldpagetext = $text[0];
	$newpagetext = "";
	$archive_count = 0;
	unset($text[0]);
	echo "start split\n";
	foreach ($text as $temp) {
		if (preg_match("/(==.+?==)/", $temp, $m)) {
			echo $m[1] . "\n";
		} else {
			echo "title get fail\n";
		}
		preg_match_all("/\d{4}年\d{1,2}月\d{1,2}日 \(.{3}\) \d{2}\:\d{2} \(UTC\)/", $temp, $m);
		$firsttime = time();
		$lasttime = 0;
		foreach ($m[0] as $timestr) {
			$time = converttime($timestr);
			if ($time > time()) {
				echo "ignore time: " . date("Y/m/d H:i", $time) . "\n";
				continue;
			}
			if ($time < $firsttime) {
				$firsttime = $time;
			}

			if ($time > $lasttime) {
				$lasttime = $time;
			}

		}
		echo "time=" . date("Y/m/d H:i:s", $firsttime) . "-" . date("Y/m/d H:i:s", $lasttime) . "\n";
		if ($lasttime == 0) {
			$oldpagetext .= $temp . "\n{{null| bot archive time: ~~~~~ }}\n";
			echo "not archive (bot)\t";
		} else if (time() - $lasttime > $cfg['retention_time']) {
			$newpagetext .= $temp;
			$archive_count++;
			echo "archive\t";
		} else {
			$oldpagetext .= $temp;
			echo "not archive\t";
		}
		echo "\n";
	}

	if ($newpagetext === "") {
		echo "no change\n";
		exit(0);
	}

	echo "start edit\n";

	echo "edit main page\n";
	$summary = sprintf($cfg["main_page_summary"], $archive_count);
	$post = array(
		"action" => "edit",
		"format" => "json",
		"title" => $C["from_page"],
		"summary" => $summary,
		"text" => $oldpagetext,
		"token" => $edittoken,
		"minor" => "",
		"starttimestamp" => $starttimestamp,
		"basetimestamp" => $basetimestamp,
	);
	echo "edit " . $C["from_page"] . " summary=" . $summary . "\n";
	if (!$C["test"]) {
		$res = cURL($C["wikiapi"], $post);
	} else {
		$res = false;
	}

	$res = json_decode($res, true);
	if (isset($res["error"])) {
		echo "edit fail\n";
		if ($i === 1) {
			echo "quit\n";
			exit(0);
		} else {
			echo "retry\n";
		}
	} else {
		break;
	}
}

$starttimestamp2 = time();
$res = cURL($C["wikiapi"] . "?" . http_build_query(array(
	"action" => "query",
	"prop" => "revisions",
	"format" => "json",
	"rvprop" => "content|timestamp",
	"titles" => $to_page_name,
)));
$res = json_decode($res, true);
$pages = current($res["query"]["pages"]);
$oldtext = "";
$basetimestamp2 = null;
if (!isset($pages["missing"])) {
	$oldtext = $pages["revisions"][0]["*"];
	$basetimestamp2 = $pages["revisions"][0]["timestamp"];
	echo $to_page_name . " exist\n";
} else {
	echo $to_page_name . " not exist\n";
	$oldtext = $cfg['archive_page_preload'] . "\n";
}
$oldtext .= "\n" . $newpagetext;
$oldtext = preg_replace("/\n{3,}/", "\n\n", $oldtext);

$summary = sprintf($cfg["archive_page_summary"], $archive_count);
$post = array(
	"action" => "edit",
	"format" => "json",
	"title" => $to_page_name,
	"summary" => $summary,
	"text" => $oldtext,
	"token" => $edittoken,
	"minor" => "",
	"starttimestamp" => $starttimestamp2,
);
if ($basetimestamp2 !== null) {
	$post["basetimestamp"] = $basetimestamp2;
}
echo "edit " . $to_page_name . " summary=" . $summary . "\n";
for ($i = $C["fail_retry"]; $i > 0; $i--) {
	if (!$C["test"]) {
		$res = cURL($C["wikiapi"], $post);
	} else {
		$res = false;
	}

	$res = json_decode($res, true);
	if (isset($res["error"])) {
		echo "edit fail\n";
		if ($i === 1) {
			echo "quit\n";
			exit(0);
		} else {
			echo "retry\n";
		}
	} else {
		break;
	}
}
echo "saved\n";

$spendtime = (microtime(true) - $starttime);
echo "spend " . $spendtime . " s.\n";
