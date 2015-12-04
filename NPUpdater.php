<?php

set_time_limit(0);
mb_internal_encoding("UTF-8");

error_reporting(E_ALL);

//	Time in seconds between checking if song has changed
$updateInterval = 2;
//	Time in seconds to wait after a COM error before trying again
$exceptionWait = 30;

//	Metadata fields to include in nowplaying
$fields = array("artist", "title", "album", "date", "length", "composer", "arranger", "lyricist", "performer", "remixer", "genre",
				"album artist", "tracknumber", "totaltracks", "discnumber", "totaldiscs", "comment", "playback_time", "path");
//	Metadata fields to include in library database
$libraryFields = array("artist", "title", "album", "date", "length", "composer", "arranger", "lyricist", "performer", "remixer", "genre",
					   "album artist", "tracknumber", "totaltracks", "discnumber", "totaldiscs", "comment");

//	Title format string to send to foobar2000 COM object, metadata will be received in this format
$titleFormat = "%". implode("%|%", $fields). "%";
$fieldCount = count($fields);


/**
 * @return PDO
 * @throws Exception Invalid auth file
 */
function getPDO(): PDO {
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

	return $pdo;
}

/**
 * Gets current nowplaying info
 * @return array Result set
 * @throws Exception PDO error
 */
function getRow(): array {
	echo "Grabbing previous info...";

	$pdo = getPDO();
	$st = $pdo->prepare("SELECT * FROM `radio`");
	$st->execute();
	$res = $st->fetch();
	$st = null;
	$pdo = null;

	if (!$res) {
		echo "none available.\n";
		$res = array();
	}
	else
		echo "done.\n";

	return $res;
}

/**
 * Update nowplaying info
 * @param $parameters array(field1 => metadata, ...
 * @return int Row update count (1 or 0)
 * @throws Exception PDO error
 */
function updateRow(array $parameters): int {
	echo "Updating database record...";

	$pdo = getPDO();
	$columnString = "`". implode("`=?, `", array_keys($parameters)). "`=?";
	$st = $pdo->prepare("UPDATE `radio` SET $columnString");
	$st->execute(array_values($parameters));
	$rowCount = $st->rowCount();
	$st = null;
	$pdo = null;
	echo ($rowCount) ? "done.\n" : "Error updating song information.\n";

	return $rowCount;
}

/**
 * Update only mid song information (playback time, paused yes/no, and stream listeners
 * @param $playback
 * @param $paused
 * @param $listeners
 * @return int Row update count (1 or 0)
 * @throws Exception PDO error
 */
function updatePlayback(string $playback, bool $paused, string $listeners): int {
	$pdo = getPDO();
	$st = $pdo->prepare("UPDATE `radio` SET `playback_time`=?, `paused`=?, `listeners`=?");
	$paused = ($paused) ? 1 : 0;
	$st->execute(array($playback, $paused, $listeners));
	$rowCount = $st->rowCount();
	$st = null;
	$pdo = null;

	return $rowCount;
}

/**
 * Insert new nowplaying information if there was no song playing previously
 * @param $parameters array(field1 => metadata, ...
 * @return int Row update count (1 or 0)
 * @throws Exception PDO error
 */
function insertRow(array $parameters): int {
	echo "Inserting database record...";

	$pdo = getPDO();
	$columnString = "`". implode("`, `", array_keys($parameters)). "`";
	$valueString = implode(", ", array_fill(0, count($parameters), "?"));
	$st = $pdo->prepare("INSERT INTO `radio` ($columnString) VALUES ($valueString)");
	$st->execute(array_values($parameters));
	$rowCount = $st->rowCount();
	$st = null;
	$pdo = null;

	echo ($rowCount) ? "done.\n" : "Error inserting song information.\n";
	return $rowCount;
}

/**
 * Delete nowplaying information when playback has stopped
 * @return int Row update count (1 or 0)
 * @throws Exception PDO error
 */
function deleteRow(): int {
	echo "Playback stopped.\nDeleting database record...";

	$pdo = getPDO();
	$st = $pdo->prepare("DELETE FROM `radio`");
	$st->execute();
	$rowCount = $st->rowCount();
	$st = null;
	$pdo = null;

	echo ($rowCount) ? "done.\n" : "Error deleting song information.";
	return $rowCount;
}

/**
 * Get number of songs in remote library to determine if it needs to be updated
 * @return int Song count
 * @throws Exception PDO error
 */
function getRemoteSongCount(): int {
	$pdo = getPDO();
	$st = $pdo->prepare("SELECT COUNT(*) FROM `library`");
	$st->execute();
	$results = $st->fetch();
	$st = null;
	$pdo = null;

	return ($results['COUNT(*)'] >= 0) ? (int)($results['COUNT(*)']) : -1;
}

/**
 * Get number of songs in local library to compare to remote library
 * @return int Song count
 */
function getLocalSongCount(): int {
	$songCount = -1;
	try {
		$com = new COM("Foobar2000.Application.0.7", null, CP_UTF8);
		$songCount = $com->MediaLibrary->GetTracks()->Count;
		$com = null;
	} catch (Exception $e) {
		echo "Unable to retrieve library song count: ". $e->getMessage(). "\nLibrary updated will be skipped.";
	}

	return ($songCount > 0) ? (int)$songCount : -1;
}

/**
 * Upload album art to web server on song change
 * @param string $file Absolute path to cover image
 * @return boolean True/false success/failure
 */
function uploadArt(string $file): bool {
	echo "done.\nUploading art to server...";

	$curl = curl_init("http://192.168.1.253/radio/upload.php");

	if (!file_exists("auth.txt") || !($auth = file("auth.txt", FILE_IGNORE_NEW_LINES)) || count($auth) < 2) {
		echo "Please store your auth information in auth.txt with Icecast and album art uploader basic auth credentials (one per line).";
		return false;
	}

	//	Get MIME type to use with CURL
	$finfo = new finfo(FILEINFO_MIME_TYPE);
	$mimeType = $finfo->file("art\\$file");
	$finfo = null;

	//	Populate CURL options
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
	$image = new CURLFile("art\\$file", $mimeType, basename($file));
	$post = array('image' => $image);
	curl_setopt($curl, CURLOPT_POST, true);
	curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
	curl_setopt($curl, CURLOPT_USERPWD, $auth[1]);

	$exit = curl_exec($curl);
	curl_close($curl);
	$image = $post = $curl = $mimeType = $file = null;

	$success = (is_numeric($exit)) ? true : false;
	if ($success)
		echo "done.\n";
	else
		echo "Unable to upload art.\n";

	//	True on exit code 0, otherwise false
	return $success;
}

/**
 * Scan the location of the song to find valid cover art file
 * COM object must be used for unicode filenames
 * @param string $path Absolute path of song location
 * @return string Absolute path of cover art file
 */
function getArtName(string $path): string {
	$art = "";
	//	Normalize $path
	if (substr($path, 0, -1) != DIRECTORY_SEPARATOR)
		$path .= DIRECTORY_SEPARATOR;

	//	Art files to search for
	$artNames = array("Folder", "Cover", "folder", "cover");
	$artExtensions = array(".jpg", ".png");

	try {
		$com = new COM("Scripting.FileSystemObject", null, CP_UTF8);

		//	Permute over art filenames
		foreach ($artNames as $name) {
			foreach ($artExtensions as $extension) {
				if ($com->FileExists($path . $name . $extension)) {
					$art = $path . $name . $extension;
					break;
				}
			}
		}
	}
	//	COM error
	catch (Exception $e) {
		echo "Unable to search for art: ". $e->getMessage(). "\n";
	}

	$com = null;
	return $art;
}

/**
 * Copy the album art file to local directory with ASCII only characters so PHP can work with it properly
 * COM object must be used for unicode filenames
 * @param string $file Absolute path of art file
 * @return bool True/false success/failure
 */
function copyArt(string $file): bool {
	$destination = getcwd(). "\\art\\". basename($file);

	echo "Copying album art...";
	try {
		$com = new COM("Scripting.FileSystemObject", null, CP_UTF8);
		$com->CopyFile($file, $destination);
		$com = null;
		return true;
	}
	//	COM error
	catch (Exception $e) {
		echo "Unable to copy art '$file' to '$destination': ". $e->getMessage(). "\n";
	}

	return false;
}

/**
 * Get the list of IP addresses that are currently streaming the radio
 * @param bool $paused True to check the fallback stream instead when music is paused (main stream will be dead)
 * @return array Array of IP addresses
 */
function getListeners(bool $paused = false): array {
	//	Basic auth for logging into icecast server
	if (!file_exists("auth.txt") || !($auth = file("auth.txt", FILE_IGNORE_NEW_LINES)) || count($auth) < 2) {
		echo "Please store your auth information in auth.txt with Icecast and album art uploader basic auth credentials (one per line).";
		return array();
	}

	//	Select correct stream
	$stream = ($paused) ? "silence.ogg" : "stream.ogg";

	//	Set up CURL
	$curl = curl_init("http://localhost:8000/admin/listclients.xsl?mount=/$stream");
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($curl, CURLOPT_USERPWD, $auth[0]);
	$page = curl_exec($curl);
	curl_close($curl);
	$curl = null;

	//	Relevant HTML not present
	if (!preg_match('/<div class="scrolltable">(.+?)<\/div>/is', $page, $table))
		return array();

	//	Grab IP addresses from listener table
	if (preg_match('/<tbody>(.+?)<\/tbody>/is', $table[1], $tbody) &&
		preg_match_all('/<tr>\s*<td>([^<]+)<\/td>/is', $tbody[1], $listeners)) {
		return $listeners[1];
	}

	//	Nothing found in listener table
	return array();
}


/**
 * BEGIN PROCEDURE
 */


//	Library update routine

//	Get remote database song count of library
$updatingLibrary = false;
$remoteSongCount = getRemoteSongCount();
$localSongCount = getLocalSongCount();

//	If the song count has changed, rebuild the remote library
if ($localSongCount > -1 && $localSongCount != $remoteSongCount) {
	echo "Number of songs in library has changed ($localSongCount local, $remoteSongCount remote). The library database will now be rebuilt.\n";

	$updaterPipes = array();
	//	Open updater script
	$updater = proc_open('php -f "LibraryUpdater.php" -- "'. implode("|", $libraryFields). '"', array(
			0 => array("pipe", "r"),
			1 => array("pipe", "w"),
			2 => array("pipe", "w")
	), $updaterPipes);

	//	Success
	if (is_resource($updater)) {
		echo "Library updater has opened successfully.\n";
		$updatingLibrary = true;
		//	Close STDIN
		fclose($updaterPipes[0]);
		//	Non-blocking for live feedback
		stream_set_blocking($updaterPipes[1], 0);
		stream_set_blocking($updaterPipes[2], 0);
	}
	else echo "Unable to open library updater.\n";
}
else
	echo "Library seems up to date, skipping updater.\n";


//	Get last known now playing state to prevent redundant updates
$row = getRow();

//	Main loop
echo "Starting NP update loop.\n";
$firstPass = true;
while (true) {

	//	Library update is in progress from LibraryUpdater.php, check the latter's STDOUT for progress
	if ($updatingLibrary) {
		$updaterStreams = array($updaterPipes[1], $updaterPipes[2]);
		$write = $except = array();

		//	New data
		if (stream_select($updaterStreams, $write, $except, 100) !== false) {
			//	Grab data
			$output = fread($updaterPipes[1], 1024);

			//	Program finished
			if (strpos($output, "snagglepuss") !== false) {
				//	Trim the flag word off the end
				$remaining = trim(substr($output, 0, -11));
				if ($remaining)
					echo "$remaining\n";

				//	Clean up
				$updatingLibrary = false;
				fclose($updaterPipes[1]);
				fclose($updaterPipes[2]);
				proc_close($updater);
				$updater = $updaterPipes = null;
			}
			//	Still running, output what we have so far
			else
				echo $output;
		}
	}


	//	Reopen COM connection to f2k
	try {
		$com = new COM("Foobar2000.Application.0.7", null, CP_UTF8);
		$playback = $com->Playback;
	}
	//	COM error, sleep
	catch (Exception $e) {
		echo "COM exception, f2k is likely shutdown. Pausing for $exceptionWait seconds.\n";
		echo $e->getMessage(). "\n";
		sleep($exceptionWait);
		continue;
	}


	//	Song is playing
	if ($playback->isPlaying) {
		$paused = $playback->isPaused;

		//	Get metadata from foobar2000
		$nowPlaying = explode("|", $playback->FormatTitleEx($titleFormat, 3));
		//	Malformed metadata returned, skip this update
		if (count($nowPlaying) < $fieldCount) {
			sleep($updateInterval);
			continue;
		}

		//	Construct column=>value pairs for database
		$parameters = array_combine($fields, $nowPlaying);
		$parameters['paused'] = ($paused) ? 1 : 0;

		//	Save and normalize path and remove (not inserted into db)
		$path = pathinfo($parameters['path'])['dirname'];
		unset($parameters['path']);

		//	Get current radio listeners from Icecast
		$listeners = getListeners($paused);
		$listenerString = implode(";", $listeners);


		//	Check if song has changed
		$newSong = false;
		//	These don't indicate a song change
		$ignoredFields = array("playback_time", "paused");
		//	No $row means no song was playing, so any song is a new song
		if (!$row)
			$newSong = true;
		else {
			//	Loop through all columns and compare
			foreach ($row as $column => $value) {
				if (isset($parameters[$column]) && !in_array($column, $ignoredFields) && $value != $parameters[$column]) {
					$newSong = true;
					break;
				}
			}
		}


		//	Song did change
		if ($newSong) {
			//	Send notice to console, strip unicode characters
			$changeNotice = mb_ereg_replace('[^a-zA-Z0-9!@#$%^&*()\-_+=\\\\\|\[\]{};:\'",.<>\/? ]', "?",
											sprintf("%s - %s [%s]", $parameters['artist'], $parameters['title'], $parameters['album'])
							);
			echo "Song changing to $changeNotice\n";

			//	New album, upload new art
			if (!isset($row['album']) || $row['album'] != $parameters['album']) {
				$cover = getArtName($path);

				//	New art copied, upload to web server
				if ($cover && copyArt($cover))
					uploadArt(basename($cover));
				else
					echo "No album art found in $path.\n";
			}

			//	Same album, copy old art if applicable
			elseif (isset($row['art']))
				$cover = $row['art'];

			//	Add db columns
			$parameters['art'] = basename($cover);
			$parameters['listeners'] = $listenerString;

			//	DB record exists, overwrite
			if ($row)
				updateRow($parameters);
			//	No DB record, create one
			else
				insertRow($parameters);

			//	Save nowplaying info
			$row = $parameters;
		}

		//	Same song, only updated playback time and playing/paused status
		else {
			if ($firstPass)
				echo "Song unchanged from previous run. Updating playback only.\n";

			updatePlayback($parameters['playback_time'], $parameters['paused'], $listenerString);
		}

	}

	//	Playback is stopped
	else {
		//	Song was playing on last update
		if ((isset($row['title']) && strlen($row['title'])) || isset($row[0]))
			deleteRow();

		//	Reset nowplaying info
		$row = array();
	}

	$firstPass = false;
	$com = null;
	sleep($updateInterval);
}