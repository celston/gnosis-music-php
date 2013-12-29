<?php namespace Gnosis\Music;

use Illuminate\Support\Facades\DB;

class MusicManager {
	private $_lastfm;
	private $_em;

	public function __construct() {
		$this->_lastfm = new \Dandelionmood\LastFm\LastFm('59d09be6bab770f89ca6eeb33ae2b266', '59d09be6bab770f89ca6eeb33ae2b266');
		$this->_em = new \Gnosis\Entity\DynamicEntityManager(new \Gnosis\Entity\LaravelEntityDataManager());
		$this->cacheHits = array();
		$this->cacheMisses = array();
	}

	public function normalizeArtistName($name) {
		$name = trim(strtoupper($name));

		$name = preg_replace('/^\W+/', '', $name);
		$name = preg_replace('/&/', 'AND', $name);
		$name = preg_replace('/^THE /', '', $name);

		$name = trim($name);

		return $name;
	}

	public function normalizeTrackName($name) {
		$name = trim(strtoupper($name));
		$name = preg_replace('/^\s*-\s*/', '', $name);
		$name = preg_replace('/\s+\W+\s*$/', '', $name);
		$name = preg_replace('/^\d\d+\.?\s+(-\s+)?(\w)/', '$2', $name);
		$name = preg_replace('/^\s*-\s*/', '', $name);
		$name = preg_replace('/\s+-\s+.*$/', '', $name);
		$name = preg_replace('/\s*\[[^\]]+\]$/', '', $name);
		$name = preg_replace('/\s*\([^\)]+\)?$/', '', $name);
		$name = preg_replace('/\s*\/\s*.+$/', '', $name);
		$name = preg_replace('/-+/', ' ', $name);

		$name = preg_replace('/&/', 'AND', $name);
		$name = preg_replace('/^THE /', '', $name);
		$name = preg_replace('/^A /', '', $name);
		$name = preg_replace('/\W+$/', '', $name);
		$name = trim($name);

		return $name;
	}

	public function buildLastFmArtistHashByPlayCount($artists) {
		$artist_hash = array();
		foreach ($artists as $artist) {
			$playcount = (int)$artist->playcount;
			$playcount *= 1000;
			if ($playcount == 0) {
				$playcount = rand(1, 999);
			}
			$found = false;
			while (!$found) {
				if (!isset($artist_hash[$playcount])) {
					$artist_hash[$playcount] = $artist->name;
					$found = true;
				}
				else {
					$playcount += rand(1, 999);
				}
			}
		}

		return $artist_hash;
	}

	public function SpotifySearchArtist($query) {
		$key = $this->_autoCacheKey();
		$result = $this->_getCache($key, 90);
		if ($result == null) {
			$spotify = new \PequenoSpotifyModule\Service\SpotifyService;
			$tries = 1;
			$max_tries = 5;
			$success = false;
			while (!$success) {
				try {
					$this->_pingDatabase();
					$result = $spotify->searchArtist($query);
					$success = true;
				}
				catch (\Exception $ex) {
					if ($tries >= $max_tries) {
						throw $ex;
					}
					$tries++;
				}
			}
			$this->_setCache($key, $result);
		}

		$list = array();
		foreach ($result as $obj) {
			$list[] = $obj;
		}

		return $list;
	}

	public function SpotifyLookupArtistAlbums($uri) {
		$key = $this->_autoCacheKey();
		$result = $this->_getCache($key, 90);
		if ($result == null) {
			$spotify = new \PequenoSpotifyModule\Service\SpotifyService;
			$tries = 1;
			$max_tries = 5;
			$success = false;
			while (!$success) {
				try {
					$this->_pingDatabase();
					$result = $spotify->lookupArtist($uri, 'album');
					$success = true;
				}
				catch (\Exception $ex) {
					if ($tries >= $max_tries) {
						throw $ex;
					}
					$tries++;
				}
			}
			$this->_setCache($key, $result);
		}

		$albums = array();
		foreach ($result->getAlbums() as $album) {
			$albums[] = $album;
		}

		return $albums;
	}

	public function SpotifyLookupAlbumTracks($uri) {
		$key = $this->_autoCacheKey();
		$result = $this->_getCache($key, 90);
		if ($result == null) {
			$spotify = new \PequenoSpotifyModule\Service\SpotifyService;
			$tries = 1;
			$max_tries = 5;
			$success = false;
			while (!$success) {
				try {
					$this->_pingDatabase();
					$result = $spotify->lookupAlbum($uri, 'trackdetail');
					$success = true;
				}
				catch (\Exception $ex) {
					if ($tries >= $max_tries) {
						throw $ex;
					}
					$tries++;
				}
			}
			$this->_setCache($key, $result);
		}

		$tracks = array();
		foreach ($result->getTracks() as $track) {
			$tracks[] = $track;
		}

		return $tracks;
	}

	public function getSpotifyArtistLookups($artist_name) {
		if (!isset($this->_spotifyArtistLookups)) {
			$this->_spotifyArtistLookups = array();
		}
		if (!isset($this->_spotifyArtistLookups[$artist_name])) {
			$lookups = array();
			$n1 = $this->normalizeArtistName($artist_name);

			$artist_search_result = $this->SpotifySearchArtist($artist_name);
			foreach ($artist_search_result as $spotify_artist) {
				$n2 = $this->normalizeArtistName($spotify_artist->getName());
				$score = levenshtein(substr($n1, 0, 255), substr($n2, 0, 255)) / min(strlen($n1), strlen($n2));
				if ($score > 0.2) {
					continue;
				}
				$artist_uri = $spotify_artist->getUri();
				$lookups[$artist_uri] = array();
				$albums = $this->SpotifyLookupArtistAlbums($artist_uri);
				foreach ($albums as $album) {
					$album_uri = $album->getUri();
					$lookups[$artist_uri][$album_uri] = array(
						'album' => $album,
						'tracks' => $this->SpotifyLookupAlbumTracks($album_uri)
					);
				}
			}

			$this->_spotifyArtistLookups[$artist_name] = $lookups;
		}

		return $this->_spotifyArtistLookups[$artist_name];
	}

	public function SpotifySearchTrackByLookups($artist_name, $track_name) {
		$matches = array();
		$available = array();
		$num_territories = 0;
		$n1 = $this->normalizeTrackName($track_name);

		foreach ($this->getSpotifyArtistLookups($artist_name) as $artist_uri => $albums) {
			foreach ($albums as $album_uri => $bundle) {
				foreach ($bundle['tracks'] as $track) {
					$n2 = $this->normalizeTrackName($track->getName());
					if (strlen($n1) > 0 && strlen($n2) > 0) {
						$score = levenshtein(substr($n1, 0, 255), substr($n2, 0, 255)) / min(strlen($n1), strlen($n2));
						if ($score <= 0.2) {
							$matches[] = $track;
							$num_territories += count($bundle['album']->getTerritories());
							if (in_array('US', $bundle['album']->getTerritories()) || in_array('worldwide', $bundle['album']->getTerritories())) {
								$available[] = $track;
							}
						}
					}
				}
			}
		}

		if (count($available) > 0) {
			return $available;
		}

		return $matches;
	}

	public function SpotifySearchTrack($query) {
		$key = $this->_autoCacheKey();
		$result = $this->_getCache($key, 90);
		if ($result == null) {
			$spotify = new \PequenoSpotifyModule\Service\SpotifyService;
			$success = false;
			$tries = 1;
			$max_tries = 5;
			while (!$success) {
				try {
					$this->_pingDatabase();
					$result = $spotify->searchTrack($query);
					$success = true;
				}
				catch (\Exception $ex) {
					if ($tries >= $max_tries) {
						#throw $ex;
						return array();
					}
					$tries++;
				}
			}
			$this->_setCache($key, $result);
		}

		$filtered = array();
		foreach ($result as $track) {
			if (in_array('US', $track->getTerritories()) || in_array('worldwide', $track->getTerritories())) {
				$filtered[] = $track;
			}
		}

		return $filtered;
	}

	public function buildSpotifyTrackHashByPopularity($tracks) {
		$track_hash = array();
		foreach ($tracks as $track) {
			$popularity = round(100 * $track->getPopularity());
			if (!isset($track_hash[$popularity])) {
				$track_hash[$popularity] = array();
			}
			$track_hash[$popularity][] = $track;
		}

		return $track_hash;
	}

	public function buildLastFmTrackHashByListeners($tracks) {
		$track_hash = array();
		foreach ($tracks as $track) {
			$listeners = (int)$track->listeners;
			if (!isset($track_hash[$listeners])) {
				$track_hash[$listeners] = array();
			}
			$track_hash[$listeners][] = $track->name;
		}

		return $track_hash;
	}

	public function drawPickFromWeightedHash($hash) {
		$pick = wrand($hash);
		if (is_array($pick)) {
			$pick = $pick[array_rand($pick)];
		}

		return $pick;
	}

	public function drawPicksFromWeightedHash($hash, $n) {
		$picks = array();
		for ($i = 0; $i < $n; $i++) {
			$pick = wrand($hash);
			if (is_array($pick)) {
				$pick = $pick[array_rand($pick)];
			}
			if (!isset($picks[$pick])) {
				$picks[$pick] = 0;
			}
			$picks[$pick]++;
		}

		return $picks;
	}

	public function LastFmTrackSimilar($artist, $track) {
		$key = $this->_autoCacheKey();
		$result = $this->_getCache($key, 1);
		if (!$result) {
			$request = new \stdClass();
			$request->artist = $artist;
			$request->track = $track;
	
			$a_request = get_object_vars($request);
	
			$response = $this->_LastFmCall('track.getsimilar', $a_request);
			if (isset($response->similartracks) && isset($response->similartracks->track)) {
				$result = $response->similartracks->track;
				$this->_setCache($key, $result);
			}
		}

		return $result;
	}

	public function LastFmUserRecentTracks($user, $days) {
		$to = time() - ($days * 60 * 60 * 24);
		$data = DB::table('LastFmUserRecentTrack')
			->where('user', $user)
			->whereRaw('date > ?', array($to))
			->get()
		;

		$result = array();
		if ($data) {
		}
		else {
			$request = new \stdClass();
			$request->user = $user;
			$request->to = $to;
	
			$temp = $this->_LastFmGetAllPages('user_getrecenttracks', $request, 'recenttracks', 'track', null, $n);

			foreach ($temp as $track) {
				$row = new \stdClass();
				$row->track = $track->name;
				$row->artist = $track->artist->{'#track'};
				$row->date = $track->date->uts;

				$result[] = $row;
			}
		}

		return $result;
		
		/*
		$key = $this->_autoCacheKey();
		$result = $this->_getCache($key, 1);
		if (!$result) {
			$request = new \stdClass();
			$request->user = $user;
	
			$result = $this->_LastFmGetAllPages('user_getrecenttracks', $request, 'recenttracks', 'track', null, $n);
			$this->_setCache($key, $result);
		}

		return $result;
		*/
	}

	public function buildTrackPlayCountHash($tracks) {
		$track_hash = array();
		$scale = 1000;
		foreach ($tracks as $track) {
			$key = $track->playcount * $scale;
			$found = false;
			while (!$found) {
				if (!isset($track_hash[$key])) {
					$track_hash[$key] = $track->name;
					$found = true;
				}
				else {
					$key += rand(1, $scale);
				}
			}
		}

		return $track_hash;
	}

	public function buildArtistHash($tracks) {
		$counts = array();
		foreach ($tracks as $track) {
			$artist_name = $track->artist->{'#text'};
			if (!isset($counts[$artist_name])) {
				$counts[$artist_name] = 0;
			}
			$counts[$artist_name]++;
		}
	
		$scale = 1000;
		$artist_hash = array();
		foreach ($counts as $artist_name => $count) {
			$key = $count * $scale;
			$found = false;
			while (!$found) {
				if (!isset($artist_hash[$key])) {
					$artist_hash[$key] = $artist_name;
					$found = true;
				}
				else {
					$key += rand(1, $scale);
				}
			}
		}

		return $artist_hash;
	}
	
	public function LastFmLibraryArtists($user) {
		$key = $this->_autoCacheKey();
		$result = $this->_getCache($key, 1);
		if (!$result) {
			$request = new \stdClass();
			$request->user = $user;
	
			$result = $this->_LastFmGetAllPages('library_getartists', $request, 'artists', 'artist');
			$this->_setCache($key, $result);
		}

		return $result;
	}

	public function LastFmTagTopArtists($tag) {
		$key = $this->_autoCacheKey();
		$result = $this->_getCache($key, 7);
		if (!$result) {
			$request = new \stdClass();
			$request->tag = $tag;
			$request->limit = 1000;
	
			$a_request = get_object_vars($request);
		
			$response = $this->_LastFmCall('tag.gettopartists', $a_request);
			$result = $response->topartists->artist;
			$this->_setCache($key, $result);
		}

		return $result;
	}

	public function LastFmUserPersonalTags($user, $tag, $taggingtype) {
		$key = $this->_autoCacheKey();
		$result = $this->_getCache($key, -1);
		if (!$result) {
			$request = new \stdClass();
			$request->user = $user;
			$request->tag = $tag;
			$request->taggingtype = $taggingtype;
	
			$result = $this->_LastFmGetAllPages('user_getpersonaltags', $request, 'taggings', 'artists', 'artist');
			$this->_setCache($key, $result);
		}

		return $result;
	}

	public function LastFmArtistSimilar($artist) {
		$key = $this->_autoCacheKey();
		$result = $this->_getCache($key, 30);
		if (!$result) {
			$request = new \stdClass();
			$request->artist = $artist;

			$a_request = get_object_vars($request);
	
			$response = $this->_LastFmCall('artist.getsimilar', $a_request);
			if (isset($response->similarartists) && isset($response->similarartists->artist)) {
				$result = $response->similarartists->artist;
				$this->_setCache($key, $result);
			}
		}

		return $result;
	}

	public function LastFmArtistInfo($artist) {
		$key = $this->_autoCacheKey();
		$result = $this->_getCache($key, 1);
		if (!$result) {
			$request = new \stdClass();
			$request->artist = $artist;

			$a_request = get_object_vars($request);
	
			$response = $this->_LastFmCall('artist.getinfo', $a_request);
			$result = $response->artist;
			$this->_setCache($key, $result);
		}

		return $result;
	}

	public function LastFmLibraryTracks($user, $artist = null) {
		$key = $this->_autoCacheKey();
		$result = $this->_getCache($key, 7);
		if (!$result) {
			$request = new \stdClass();
			$request->user = $user;
			if ($artist != null) {
				$request->artist = $artist;
			}
	
			$result = $this->_LastFmGetAllPages('library_gettracks', $request, 'tracks', 'track');
			$this->_setCache($key, $result);
		}

		return $result;
	}

	public function LastFmArtistTracks($artist, $limit = 400) {
		$key = $this->_autoCacheKey();
		$result = $this->_getCache($key, 7);
		if (!$result) {
			$artist = strtolower($artist);

			$request = new \stdClass();
			$request->artist = $artist;
	
			$result = $this->_LastFmGetAllPages('artist_gettoptracks', $request, 'toptracks', 'track', null, $limit);
			$this->_setCache($key, $result);
		}
	
		return array_slice($result, 0, $limit);
	}

	public function LastFmUserArtists($user) {
		$key = $this->_autoCacheKey();
		$result = $this->_getCache($key, 7);
		if (!$result) {
			$request = new \stdClass();
			$request->user = $user;
			$request->period = 'overall';

			die('TODO');
		}
	}

	private function _LastFmGetAllPages($method, $request, $result_property1, $result_property2, $result_property3, $limit) {
		$page = $this->_LastFmCall($method, $request);

		$total_pages = $page->{$result_property1}->totalPages;

		$result = array();


					/*
					if (is_array($response->{$result_property1}->{$result_property2})) {
						$result = array_merge($result, $response->{$result_property1}->{$result_property2});
					}
					else {
						$result = array_merge($result, array($response->{$result_property1}->{$result_property2}));
					}
				}
				else {
					if (is_array($response->{$result_property1}->{$result_property2}->{$result_property3})) {
						$result = array_merge($result, $response->{$result_property1}->{$result_property2}->{$result_property3});
					}
					else {
						$result = array_merge($result, array($response->{$result_property1}->{$result_property2}->{$result_property3}));
					}
				}
			}
			*/
		}

		return $result;
	}

	private function _CorrectObject($obj) {
		if (isset($obj->{'@attr'})) {
			foreach ($obj->{'@attr'} as $key => $value) {
				$obj->{$key} = $value;
			}
			unset($obj->{'@attr'});
		}

		foreach (get_object_vars($obj) as $key => $value) {
			if (is_object($value)) {
				$this->_CorrectObject($value);
			}
			elseif (is_array($value)) {
				foreach ($value as $subvalue) {
					if (is_object($subvalue)) {
						$this->_CorrectObject($subvalue);
					}
				}
			}
		}
	}

	private function _autoCacheKey($depth=1) {
		$bt = debug_backtrace();
		
		return sprintf('%s|%s|%s', $bt[$depth]['class'], $bt[$depth]['function'], serialize($bt[$depth]['args']));
	}

	private function _LastFmCall($method, $request) {
		$tries = 0;
		while (TRUE) {
			try {
				usleep(200);
				$this->_pingDatabase();
				return $this->_lastfm->{$method}($request);
			}
			catch (\Exception $ex) {
				$tries++;
				if ($tries >= 50) {
					throw $ex;
				}
			}
		}
	}

	private function _pingDatabase() {
		DB::select('SELECT 1');
	}

	private function _getCache($key, $days) {
		/*
		$md5 = md5($key, TRUE);
		$data = DB::table('cache')->where('id', $md5)->pluck('data');
		*/
		$data = DB::table('cache')
			->whereRaw('id_md5 = UNHEX(MD5(?))', array($key))
			->whereRaw("created > DATE_SUB(UTC_TIMESTAMP, INTERVAL $days DAY)")
			->pluck('data');

		if ($data) {
			$this->cacheHits[] = $key;
			DB::update('UPDATE cache SET accessed = UTC_TIMESTAMP WHERE id_md5 = UNHEX(MD5(?))', array($key));
			try {
				$temp = @gzuncompress($data);
				return unserialize($temp);
			}
			catch (\ErrorException $ex) {
				return null;
			}
		}

		$this->cacheMisses[] = $key;
		return null;
	}

	private function _setCache($key, $data) {
		#$id = md5($key, TRUE);
		DB::delete('DELETE FROM cache WHERE id_md5 = UNHEX(MD5(?))', array($key));
		DB::insert('INSERT INTO cache (id, id_md5, created, data) VALUES (?, UNHEX(MD5(?)), UTC_TIMESTAMP(), ?)', array($key, $key, gzcompress(serialize($data))));
		$test = DB::table('cache')->where('id', $key)->pluck('data');
		if (!$test) {
			die("Failed to set cache for $key");
		}
	}

}

