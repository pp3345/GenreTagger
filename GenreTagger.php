<?php

	chdir(__DIR__);

	require_once "vendor/autoload.php";

	$options = getopt("", ["no-retag"], $stop);

	$directory = array_slice($argv, $stop);
	if(!$directory) {
		echo "Missing directory argument" . PHP_EOL;
		exit(1);
	}
	if(count($directory) > 1) {
		echo "Too many arguments" . PHP_EOL;
		exit(1);
	}

	define("KEY", trim(file_get_contents("./key")));
	define("TOP_DIRECTORY", $directory[0]);

	if(!file_exists(TOP_DIRECTORY) || !is_readable(TOP_DIRECTORY) || !is_dir(TOP_DIRECTORY)) {
		echo "Invalid directory " . TOP_DIRECTORY . PHP_EOL;
		exit(1);
	}

	$tags     = array_change_key_case(array_flip(json_decode(file_get_contents("tags.json"), true)), CASE_LOWER);
	$mappings = json_decode(file_get_contents("tag_mappings.json"), true);

	$resolve = function (string $tag) use ($mappings, &$resolve): array {
		if(!isset($mappings[$tag]))
			return [$tag];

		$ret = [];

		foreach($mappings[$tag] as $target) {
			if($target == $tag) {
				$ret[$tag] = $tag;
				continue;
			}

			$temp = $resolve($target);
			foreach($temp as $v) {
				$ret[$v] = $v;
			}
		}

		return $ret;
	};

	$handleTagList = function (array $list) use ($tags, $resolve): array {
		$track_tags = [];

		foreach($list as $tag) {
			$tag = strtolower($tag->name);
			if(!isset($tags[$tag]))
				continue;

			$mappings = $resolve($tags[$tag]);
			foreach($mappings as $v) {
				$track_tags[$v] = $v;
			}
		}

		return $track_tags;
	};

	$fetchTrackTags = function ($artist, $title) use ($handleTagList): array {
		while(!isset($track->track)) {
			$track = json_decode(file_get_contents("https://ws.audioscrobbler.com/2.0/?method=track.getInfo&api_key=" . KEY . "&format=json&artist=" . urlencode($artist) . "&track=" . urlencode($title) . "&autocorrect=1"));

			if(isset($track->error))
				return [];
		}

		return $handleTagList($track->track->toptags->tag);
	};

	$fetchArtistTags = function ($artist) use ($handleTagList): array {
		while(!isset($artist->artist)) {
			$artist = json_decode(file_get_contents("https://ws.audioscrobbler.com/2.0/?method=artist.getInfo&api_key=" . KEY . "&format=json&artist=" . urlencode($artist) . "&autocorrect=1"));

			if(isset($artist->error))
				return [];
		}

		return $handleTagList($artist->artist->tags->tag);
	};

	foreach(scandir(TOP_DIRECTORY) as $artist) {
		if($artist[0] == ".")
			continue;

		foreach(scandir(TOP_DIRECTORY . "/" . $artist) as $album) {
			if($album[0] == ".")
				continue;

			foreach(scandir(TOP_DIRECTORY . "/" . $artist . "/" . $album) as $track) {
				if($track[0] == ".")
					continue;

				if(substr($track, -4, 4) != "flac")
					continue;

				$path          = TOP_DIRECTORY . "/" . $artist . "/" . $album . "/" . $track;
				$analyzed      = (new \JamesHeinrich\GetID3\GetID3())->analyze($path);
				$vorbisComment = $analyzed["tags"]["vorbiscomment"];
				$vorbisComment = array_change_key_case($vorbisComment, CASE_UPPER);

				$writer               = new \JamesHeinrich\GetID3\WriteTags();
				$writer->filename     = $path;
				$writer->tagformats   = ["metaflac"];
				$writer->tag_encoding = "UTF-8";

				if(!isset($vorbisComment["ARTIST"])) {
					echo "WARNING: Missing artist ($path)" . PHP_EOL;
					continue;
				}

				if(!isset($vorbisComment["TITLE"])) {
					echo "WARNING: Missing title ($path)" . PHP_EOL;
					continue;
				}

				foreach($vorbisComment as $name => $value) {
					if($name == "GENRE") {
						if(isset($options["no-retag"]))
							continue 2;

						continue;
					}

					foreach($value as $part)
						$writer->tag_data[$name][] = $part;
				}

				$artist_name = $vorbisComment["ARTIST"][0];
				$title       = $vorbisComment["TITLE"][0];

				echo "$artist_name - $title" . PHP_EOL;

				if(($p = stripos($title, "remix")) !== false) {
					unset($search);

					if(strpos($title, ")", $p)) {
						$search = "(";
					} else if(strpos($title, "]", $p)) {
						$search = "[";
					}

					if(isset($search)) {
						$pos = $p;
						while($title[--$pos] != $search && $pos > 0)
							;

						if($pos > 0)
							$title = substr($title, 0, $pos);
					}
				}

				$title = trim($title);

				$track_tags = $fetchTrackTags($artist_name, $title);
				if(!$track_tags && strpos($artist_name, ",")) {
					$track_tags = $fetchTrackTags(str_replace(",", " &", $artist_name), $title);
				}
				if(!$track_tags && strpos($artist_name, ",")) {
					$track_tags = $fetchTrackTags(str_replace(",", " feat. ", $artist_name), $title);
				}
				if(!$track_tags && strpos($artist_name, "&")) {
					$track_tags = $fetchTrackTags(str_replace("&", ",", $artist_name), $title);
				}
				if(!$track_tags && strpos($artist_name, "&")) {
					$track_tags = $fetchTrackTags(str_replace("&", " feat. ", $artist_name), $title);
				}
				if(!$track_tags && $artist_name != $artist) {
					$track_tags = $fetchTrackTags($artist, $title);
				}
				if(!$track_tags) {
					$search = json_decode(file_get_contents("https://ws.audioscrobbler.com/2.0/?method=track.search&api_key=" . KEY . "&format=json&track=" . urlencode($title)));
					foreach($search->results->trackmatches->track as $result) {
						if(!stristr($result->artist, $artist_name) && !stristr($artist_name, $result->artist)
						   && !stristr($result->artist, $artist) && !stristr($artist, $result->artist)
						   && soundex($result->artist) != soundex($artist_name)
						   && soundex($result->artist) != soundex($artist)
						   && levenshtein($result->artist, $artist_name) > strlen($artist_name) / 3)
							continue;

						$track_tags = $fetchTrackTags($result->artist, $result->name);
						if($track_tags)
							break;
					}
				}
				if(!$track_tags) {
					$track_tags = $fetchArtistTags($artist_name);
				}
				if(!$track_tags) {
					$track_tags = $fetchArtistTags($artist);
				}

				if(!$track_tags) {
					echo "\tWARNING: Empty tags!" . PHP_EOL;
				}

				foreach($track_tags as $tag) {
					echo "\t" . $tag . PHP_EOL;
					$writer->tag_data["GENRE"][] = $tag;
				}

				if($writer->WriteTags())
					foreach($writer->warnings as $error)
						echo "\tWARNING: $error" . PHP_EOL;
				else foreach($writer->errors as $error) {
					// Ignore stupid errors
					if(strpos($error, "File modification timestamp has not changed") === false)
						echo "\tWARNING: $error" . PHP_EOL;
				}
			}
		}
	}