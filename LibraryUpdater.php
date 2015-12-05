<?php
/**
 * Radio - LibraryUpdater.php
 * User: Benjamin
 * Date: 02/12/2015
 */
//	test
//	Grab metadata fields from command line argument and form them into foobar2000 titleformat string, column names string and value placeholder string for mysql
$fields = explode("|", $argv[1]);
$titleFormat = "%". implode("%|%", $fields). "%";
$columnString = "`". implode("`, `", $fields). "`";
$valueString = implode(", ", array_fill(0, count($fields), "?"));

if (!file_exists("mysql.txt") || !($auth = file("mysql.txt", FILE_IGNORE_NEW_LINES)) || count($auth) < 4)
	throw new Exception("Please store your database information in mysql.txt with host, database, username, and password (one per line).");

$pdo = null;
while (!($pdo instanceof PDO)) {
	try {
		$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s;charset=utf8", $auth[0], $auth[1]), $auth[2], $auth[3],
					   array(	PDO::ATTR_EMULATE_PREPARES		=> false,
								 PDO::ATTR_ERRMODE				=> PDO::ERRMODE_EXCEPTION,
								 PDO::ATTR_DEFAULT_FETCH_MODE	=> PDO::FETCH_ASSOC)
		);
	}
	catch (Exception $e) {
		echo "Unable to create PDO object: ". $e->getMessage(). "\nRetrying in 5 seconds...\n";
		sleep(5);
		$pdo = null;
	}
}


$pdo->beginTransaction();

//	Clear library before rebuilding
$st = $pdo->prepare("TRUNCATE TABLE `library`");
$st->execute();

$st = $pdo->prepare("INSERT INTO `library` ($columnString) VALUES ($valueString)");

try {
	$com = new COM("Foobar2000.Application.0.7", null, CP_UTF8);
	$tracks = $com->MediaLibrary->GetTracks();
	$count = $tracks->Count;
	$processed = 0;

	echo "Updating library...";
	foreach ($tracks as $track) {
		//	Insert track
		$parameters = explode("|", $track->FormatTitle($titleFormat));
		$st->execute($parameters);

		//	Progress update
		if (++$processed % 500 == 0)
			echo round($processed / $count * 100, 2). "%...";
	}

	$pdo->commit();
	echo "done.";
	$com = null;
}
catch (Exception $e) {
	echo "Error while updating library: ". $e->getMessage();
	$pdo->rollBack();
}

//	Signals end of program
echo "snagglepuss";