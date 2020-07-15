<?php

class TwitchVOD {

	public $vod_path = 'vods';

	public $filename = '';
	public $basename = '';
	public $json = [];

	public $streamer_name = null;
	public $streamer_id = null;

	public $segments = [];

	/**
	 * Games
	 *
	 * @var [ 'time', 'game_id', 'game_name', 'viewer_count', 'title', 'datetime', 'offset', 'duration' ]
	 */
	public $games = [];

	public $started_at = null;
	public $ended_at = null;

	public $duration = null;

	public $game_offset = null;

	public $twitch_vod_id = null;
	public $twitch_vod_url = null;
	public $twitch_vod_exists = null;

	public $is_recording = false;
	public $is_converted = false;

	// public function __constructor(){

	//}

	public function load( $filename ){

		TwitchHelper::log( TwitchHelper::LOG_DEBUG, "Loading VOD Class for " . $filename);

		if(!file_exists($filename)){
			TwitchHelper::log( TwitchHelper::LOG_ERROR, "VOD Class for " . $filename . " not found");
			throw new Exception('VOD not found');
			return false;
		}

		$data = file_get_contents($filename);
		$this->json = json_decode($data, true);

		if( !$this->json['meta']['data'][0]['user_name'] ){
			TwitchHelper::log( TwitchHelper::LOG_ERROR, "Tried to load " . $filename . " but found no streamer name");
			throw new Exception('Tried to load ' . $filename . ' but found no streamer name');
			return false;
		}

		if( $this->json['started_at'] ){
			$this->started_at = DateTime::createFromFormat("Y-m-d\TH:i:s\Z", $this->json['started_at'] );
		}

		if( $this->json['ended_at'] ){
			$this->ended_at = DateTime::createFromFormat("Y-m-d\TH:i:s\Z", $this->json['ended_at'] );
		}

		$this->filename = $filename;
		// $this->filesize = filesize($filename);
		$this->basename = basename($filename, '.json');

		$this->segments = $this->json['segments'];
		
		$this->parseGames($this->json['games']);

		$this->streamer_name = $this->json['meta']['data'][0]['user_name'];
		$this->streamer_id = TwitchHelper::getChannelId( $this->streamer_name );

		$this->twitch_vod_id 	= $this->json['twitch_vod_id'];
		$this->twitch_vod_url 	= $this->json['twitch_vod_url'];	
		$this->duration 		= $this->json['duration'];

		$this->is_recording = file_exists( TwitchConfig::cfg('vod_folder') . '/' . $this->basename . '.ts' );
		$this->is_converted = file_exists( TwitchConfig::cfg('vod_folder') . '/' . $this->basename . '.mp4' );

		$this->is_chat_downloaded = file_exists( TwitchConfig::cfg('vod_folder') . '/' . $this->basename . '.chat' );

		return true;

	}

	public function getDuration( $save = false ){

		if( $this->duration ) return $this->duration;

		$getID3 = new getID3;

		$file = $getID3->analyze( TwitchConfig::cfg('vod_folder') . '/' . basename( $this->segments[0] ) );

		if( !$file['playtime_string'] ){

			if(!$file){
				TwitchHelper::log(TwitchHelper::LOG_ERROR, "Could not parse ID3 of " . $this->basename);
				return false;
			}

			TwitchHelper::log(TwitchHelper::LOG_ERROR, "Could not find duration of " . $this->basename . ": " . join(", ", $file['error']) );			

			return false;

		}else{
		
			$this->duration = $file['playtime_string'];

			if( $save ){
				TwitchHelper::log(TwitchHelper::LOG_INFO, "Saved duration for " . $this->basename);
				$this->saveJSON();
			}

			return $file['playtime_string'];

		}

	}

	/**
	 * Download chat with tcd
	 * @param  int 		$video_id [description]
	 * @param  string 	$basename [description]
	 * @return array    filename, cmd output, cmd
	 */
	public function downloadChat(){

		if(!file_exists(TwitchConfig::cfg('bin_dir') . '/tcd')){
			throw new Exception('tcd not found');
			return false;
		}

		if(!$this->twitch_vod_id){
			throw new Exception('no twitch vod id');
			return false;
		}

		$chat_filename = TwitchConfig::cfg('vod_folder') . '/' . $this->basename . '.chat';

		$tcd_filename = TwitchConfig::cfg('vod_folder') . '/' . $this->twitch_vod_id . '.json';

		if( file_exists( $chat_filename ) ){
			TwitchHelper::log(TwitchHelper::LOG_ERROR, "Chat already exists for " . $this->basename);
			return;
		}

		// if tcd generated file exists, rename it
		if( file_exists( $tcd_filename ) ){
			TwitchHelper::log(TwitchHelper::LOG_ERROR, "Renamed chat file for " . $this->basename);
			rename( $tcd_filename, $chat_filename );
			return;
		}

		if( TwitchConfig::cfg('pipenv') ){
			$cmd = 'pipenv run tcd';
		}else{
			$cmd = TwitchConfig::cfg('bin_dir') . '/tcd';
		}
		
		$cmd .= ' --video ' . escapeshellarg($this->twitch_vod_id);
		$cmd .= ' --client-id ' . escapeshellarg( TwitchConfig::cfg('api_client_id') );
		$cmd .= ' --client-secret ' . escapeshellarg( TwitchConfig::cfg('api_secret') );
		$cmd .= ' --format json';
		$cmd .= ' --output ' . TwitchConfig::cfg('vod_folder');
		// $cmd .= ' --output ' . escapeshellarg($chat_filename);

		$capture_output = shell_exec( $cmd );

		rename( $tcd_filename, $chat_filename );

		return [$chat_filename, $capture_output, $cmd];

	}

	public function matchTwitchVod(){

		TwitchHelper::log( TwitchHelper::LOG_INFO, "Try to match twitch vod for " . $this->basename);

		$channel_videos = TwitchHelper::getVideos( $this->streamer_id );

		$vod_id = null;

		foreach ($channel_videos as $vid) {
			
			$video_time = DateTime::createFromFormat( TwitchConfig::cfg('date_format'), $vid['created_at'] );

			// if within 5 minutes difference
			if( abs( $this->started_at->getTimestamp() - $video_time->getTimestamp() ) < 300 ){
				$this->twitch_vod_id = $vid['id'];
				$this->twitch_vod_url = $vid['url'];
				TwitchHelper::log( TwitchHelper::LOG_INFO, "Matched twitch vod for " . $this->basename);
				return $this->twitch_vod_id;
			}

		}

		TwitchHelper::log( TwitchHelper::LOG_ERROR, "Couldn't match vod for " . $this->basename);

	}

	public function checkValidVod(){

		TwitchHelper::log( TwitchHelper::LOG_INFO, "Check valid vod for " . $this->basename);

		$video = TwitchHelper::getVideo( $this->twitch_vod_id );

		if( $video ){
			$this->twitch_vod_exists = true;
			return true;
		}

		$this->twitch_vod_exists = false;

		return false;

	}

	/**
	 * Save JSON to file, be sure to load it first!
	 */
	public function saveJSON(){

		$generated = $this->json;

		if( $this->twitch_vod_id && $this->twitch_vod_url){
			$generated['twitch_vod_id'] 	= $this->twitch_vod_id;
			$generated['twitch_vod_url'] 	= $this->twitch_vod_url;
		}

		$generated['streamer_name'] 	= $this->streamer_name;
		$generated['streamer_id'] 		= $this->streamer_id;

		$generated['games'] 			= $this->games;
		$generated['segments'] 			= $this->segments;

		$generated['duration'] 			= $this->duration;

		file_put_contents($this->filename, json_encode($generated));

		return $generated;

	}

	private function parseGames( $array ){

		$games = [];

		foreach ($this->json['games'] as $game) {
			
			$entry = $game;

			$entry['datetime'] = DateTime::createFromFormat( TwitchConfig::cfg("date_format"), $entry['time'] );

			if($this->started_at){
				$entry['offset'] = $entry['datetime']->getTimestamp() - $this->started_at->getTimestamp();
			}

			$games[] = $entry;

		}

		$i = 0;

		foreach ($games as $game) {
			
			if($games[$i+1]){
				$games[$i]['duration'] = $games[$i+1]['datetime']->getTimestamp() - $game['datetime']->getTimestamp();
			}

			if($i == 0){
				$this->game_offset = $game['offset'];
			}

			if($i == sizeof($games)-1 && $this->ended_at){
				$games[$i]['duration'] = $this->ended_at->getTimestamp() - $game['datetime']->getTimestamp();
			}

			$i++;

		}

		$this->games = $games;

	}

	public function saveLosslessCut(){

		TwitchHelper::log( TwitchHelper::LOG_INFO, "Saving lossless cut csv for " . $this->basename);

		$data = "";

		foreach( $this->games as $k => $game ){

			$offset = $game['offset'];

			$offset -= $this->games[0]['offset'];
			
			$data .= $offset . ',';
			
			if( $k < sizeof($this->games)-1 ){
				$data .= ( $offset + $game['duration'] ) . ',';
			}else{
				$data .= ',';
			}

			$data .= $game['game_name'] ?: $game['game_id'];
			$data .= "\n";
		}

		file_put_contents( $this->vod_path . '/' . $this->basename . '-llc-edl.csv', $data );

	}

	/**
	 * Delete everything about the VOD, trying to rewrite this
	 *
	 * @return void
	 */
	public function delete(){
		
		TwitchHelper::log( TwitchHelper::LOG_INFO, "Delete " . $this->basename);
		
		// segments
		foreach($this->segments as $s){
			unlink( TwitchConfig::cfg('vod_folder') . '/' . basename($s) );
		}

		unlink( TwitchConfig::cfg('vod_folder') . '/' . $this->basename . '.json'); // data file
		unlink( TwitchConfig::cfg('vod_folder') . '/' . $this->basename . '-llc-edl.csv'); // losslesscut
		unlink( TwitchConfig::cfg('vod_folder') . '/' . $this->basename . '.chat'); // chat download

	}

	/**
	 * Save vod to saved folder, not really that functional
	 *
	 * @return void
	 */
	public function save(){
		TwitchHelper::log( TwitchHelper::LOG_INFO, "Save " . $this->basename);
		rename( TwitchConfig::cfg('vod_folder') . '/' . $this->basename . '.mp4', TwitchConfig::cfg('vod_folder') . '/saved/' . $this->basename . '.mp4');
		rename( TwitchConfig::cfg('vod_folder') . '/' . $this->basename . '.json', TwitchConfig::cfg('vod_folder') . '/saved/' . $this->basename . '.json');
		rename( TwitchConfig::cfg('vod_folder') . '/' . $this->basename . '-llc-edl.csv', TwitchConfig::cfg('vod_folder') . '/saved/' . $this->basename . '-llc-edl.csv'); // losslesscut
		rename( TwitchConfig::cfg('vod_folder') . '/' . $this->basename . '.chat', TwitchConfig::cfg('vod_folder') . '/saved/' . $this->basename . '.chat'); // chat
	}

}
