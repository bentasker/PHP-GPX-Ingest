<?php
/** GPXIngest Class
*
* Class to Ingest a basic GPX file, generate stats, convert it into an object and allow some basic manipulation.
* After ingest, the object can be output as JSON for easy storage with stats intact.
*
* @copyright (C) 2013 B Tasker (http://www.bentasker.co.uk). All rights reserved
* @license GNU GPL V2 - See LICENSE
*
* @version 1.2
*
*/

class GPXIngest{

	var $file;
	var $xml;
	var $journey;
	var $tracks = array();
	var $highspeeds;
	var $lowspeeds;
	var $journeyspeeds;
	var $totaltimes;
	var $ftimes;
	var $trackduration;
	var $smarttrack=true;
	var $smarttrackthreshold = 3600;
	var $suppresslocation = false;
	var $suppressspeed = false;
	var $suppresselevation = false;
	var $suppressdate = false;




	/** Standard constructor
	*
	*/
	function __construct(){
		$this->journey = new stdClass();
	}


	/** Reset all relevant class variables - saves unloading the class if processing multiple files
	*
	*/
	function reset(){
		$this->journey = new stdClass();
		$this->tracks = array();
	}


	/** Load XML from a file
	*
	* @arg $file - string to XML file, can be relative or full path
	*
	*/
	function loadFile($file){
		$this->xml = simplexml_load_file($file);
		if ($this->xml){
			return true;
		}else{
			$this->xml = false;
			return false;
		}
	}


	/** Load an XML string
	*
	* @arg $str - XML String
	*
	*/
	function loadString($str){
		$this->xml = simplexml_load_string($str);
		if ($this->xml){
			return true;
		}else{
			$this->xml = false;
			return false;
		}
	}



	/** Load a JSON string into the object
	*
	* Useful if you've previously parsed a GPX file with this class and want to manipulate it at a later date
	*
	* @arg $json - JSON encapsulated string
	*
	*/
	function loadJSON($json){
		$this->journey = json_decode($json);

		if (!is_object($this->journey)){
			$this->journey = false;
			return false;
		}

		// We need to update our internal stats as well
		foreach ($this->journey->journeys as $k => $v){
			$this->tracks[$k]['name'] = $this->journey->journeys->$k->name;
			$this->tracks[$k]['segments'] = array();

			foreach ($v->segments as $seg => $segv){
				$this->tracks[$k]['segments'][$seg] = count((array)$v->segments->$seg->points);
			}
		}

	}


	/** Load a JSON string from a file
	*
	*/
	function loadJSONFile($file){
		$this->loadJSON(file_get_contents($file));
	}



	/** Toggle SmartTrack on/off
	*
	*/
	function toggleSmartTrack(){
		$this->smarttrack = ($this->smarttrack)? false : true;
		return $this->smarttrack;
	}



	/** Get the current Smart Track Status
	*
	*/
	function smartTrackStatus(){
		return $this->smarttrack;
	}


	/** Get the smartTrackThreshold
	*
	*/
	function smartTrackThreshold(){
		return $this->smarttrackthreshold;
	}



	/** Set the smart Track trigger threshold
	*
	*/
	function setSmartTrackThreshold($thresh){
		$this->smarttrackthreshold = $thresh;
	}





	/** Ingest the XML and convert into an object
	* 
	* Also updates our reference arrays
	*
	*/
	function ingest(){

		if (!is_object($this->xml)){
			return false;
		}

		// Initialise the object
		$this->journey->created = new stdClass();
		$this->journey->stats = new stdClass();
		$this->journey->stats->trackpoints = 0;
		$this->journey->stats->recordedDuration = 0;
		$this->journey->stats->segments = 0;
		$this->journey->stats->tracks = 0;

		// Initialise the stats array
		$this->totaltimes = array();
		$this->highspeeds = array();
		$this->journeyspeeds = array();
		$this->lowspeeds = array();
		

		// Add the metadata
		$this->journey->created->creator = (string) $this->xml['creator'];
		$this->journey->created->version = (string) $this->xml['version'];
		$this->journey->created->format = 'GPX';

		if (!$this->suppressdate && isset($this->xml->time)){
			$this->journey->created->time = strtotime($this->xml->time);
		}

		$this->journey->timezone = date_default_timezone_get();

	
		$a = 0;

		// There may be multiple tracks in one file
		foreach ($this->xml->trk as $trk){

			// Initialise the stats variables
			$this->resetTrackStats();
			$b = 0;

			// Set the object key
			//$jkey = "journey$a";
			$jkey = $this->genTrackKey($a);
			$this->initTrack($jkey,$trk->name);


			// There may be multiple segments if GPS connectivity was lost - process each seperately
			foreach ($trk->trkseg as $trkseg){

				// Initialise the sub-stats variable
				/*
				$speed = 0;
				$sspeed = array();
				*/
				$this->resetSegmentStats();
				$x = 0;
				$lasttime = false;
				$times = array();

				// Set the segment key
				$segkey = $this->genSegKey($b);


				// Initialise the segment object
				$this->initSegment($jkey,$segkey);
				

				// Trackpoint details in trk - Push them into our object
				foreach ($trkseg->trkpt as $trkpt){

					// Initialise some variables
					$key = "trackpt$x";
					$ptspeed = (int)filter_var($trkpt->desc, FILTER_SANITIZE_NUMBER_INT);

					$time = strtotime($trkpt->time);

					// If smarttrack is enabled, check the trackpt time difference
					if ($this->smarttrack && $lasttime && (($time - $lasttime) > $this->smarttrackthreshold)){

						// We need to start a new track, but first we have to finalise the stats
						$this->writeSegmentStats($jkey,$segkey,$times,$x);
						$this->writeTrackStats($jkey);
						
						// Reset the segment counter
						$b=0;

						// Update the track counter
						$a++;

						// Reset the Key
						$x=0;
						$key = "trackpt$x";
						$times = array();

						// Get a new track key
						$jkey = $this->genTrackKey($a);
						$this->initTrack($jkey,$trk->name.$a);


						// Get a new segment key
						$segkey = $this->genSegKey($b);
						$this->initSegment($jkey,$segkey);
						
						// Reset stats
						$this->resetTrackStats();
						$this->resetSegmentStats();

					}


					// Write the track data - take into account whether we've suppressed any data elements
					if (!$this->suppresslocation){
						$this->journey->journeys->$jkey->segments->$segkey->points->$key->lat = (string) $trkpt['lat'];
						$this->journey->journeys->$jkey->segments->$segkey->points->$key->lon = (string) $trkpt['lon'];
					}

					if (!$this->suppresselevation){
						$this->journey->journeys->$jkey->segments->$segkey->points->$key->elevation = (string) $trkpt->ele;
					}

					if (!$this->suppressdate){
						$this->journey->journeys->$jkey->segments->$segkey->points->$key->time = $time;
						// Update the times arrays
						$times[] = $time;

						// update lasttime - used by SmartTrack
						$lasttime = $time;
					}


					if (!$this->suppressspeed){
						$this->journey->journeys->$jkey->segments->$segkey->points->$key->speed = (string) $trkpt->desc;
						$this->journey->journeys->$jkey->segments->$segkey->points->$key->speedint = $ptspeed;

						// Calculate speed stats
						$this->speed = $this->speed + $ptspeed;
						$this->fspeed[] = $ptspeed;
						$this->sspeed[] = $ptspeed;
					}else{
						// We also use the speed array to identify the number of trackpoints
						$this->fspeed[] = 1;
					}

					// Up the counters
					$x++;
				}

				$this->writeSegmentStats($jkey,$segkey,$times,$x);
				$b++;
				
			}


			$this->writeTrackStats($jkey);

		}

		$modesearch = array_count_values($this->journeyspeeds);
		

		// Finalise the object stats - again take suppression into account

		if (!$this->suppressdate){
			$this->journey->stats->start = min($this->totaltimes);
			$this->journey->stats->end = max($this->totaltimes);
		}

		if (!$this->suppressspeed){
			$this->journey->stats->maxSpeed = max($this->highspeeds);
			$this->journey->stats->minSpeed = min($this->lowspeeds);
			$this->journey->stats->modalSpeed = array_search(max($modesearch),$modesearch);
			$this->journey->stats->avgspeed = round(array_sum($this->journeyspeeds) / $this->journey->stats->trackpoints,2);
		}

		// Add any relevant metadata
		$this->journey->metadata = new stdClass();
		$this->journey->metadata->smartTrackStatus = ($this->smartTrackStatus())? 'enabled' : 'disabled';
		$this->journey->metadata->smartTrackThreshold = $this->smartTrackThreshold();
		$this->journey->metadata->suppression = array();

		$this->writeSuppressionMetadata();

		// XML Ingest and conversion done!
	}




	/** Update the Journey object's metadata to include details of what information (if any) was suppressed at ingest
	*
	*/
	function writeSuppressionMetadata(){

		if ($this->suppresslocation){
			$this->journey->metadata->suppression[] = 'location';
		}
		if ($this->suppressspeed){
			$this->journey->metadata->suppression[] = 'speed';
		}
		if ($this->suppresselevation){
			$this->journey->metadata->suppression[] = 'elevation';
		}
		if ($this->suppressdate){
			$this->journey->metadata->suppression[] = 'dates';
		}

	}


	/** Generate a track identifier
	*
	*/
	function genTrackKey($i){
		return "journey$i";
	}



	/** Generate a segment identifier
	*
	*/
	function genSegKey($i){
		return "seg$i";
	}




	/** Initialise a Segment object
	*
	*/
	function initSegment($jkey,$segkey){
		$this->journey->journeys->$jkey->segments->$segkey = new stdClass();
	}



	/** Initialise a track object
	*
	*/
	function initTrack($jkey,$trk){

		$this->journey->journeys->$jkey = new stdClass();
		$this->journey->journeys->$jkey->segments = new stdClass();
		$this->journey->journeys->$jkey->name = (string) $trk;
		$this->journey->journeys->$jkey->stats->journeyDuration = 0;
		$this->tracks[$jkey]['name'] = $this->journey->journeys->$jkey->name;
		$this->tracks[$jkey]['segments'] = array();
	}



	/** Write stats for the current segment
	*
	*/
	function writeSegmentStats($jkey,$segkey,$times,$x){


		if (!$this->suppressspeed){
			$modesearch = array_count_values($this->sspeed); 
			$this->journey->journeys->$jkey->segments->$segkey->stats->avgspeed = round($this->speed/$x,2);
			$this->journey->journeys->$jkey->segments->$segkey->stats->modalSpeed = array_search(max($modesearch), $modesearch);
			$this->journey->journeys->$jkey->segments->$segkey->stats->minSpeed = min($this->sspeed);
			$this->journey->journeys->$jkey->segments->$segkey->stats->maxSpeed = max($this->sspeed);

		}


		if (!$this->suppressdate){
			$start = min($times);
			$end = max($times);
			$duration = $end - $start;
			$this->journey->journeys->$jkey->segments->$segkey->stats->start = $start;
			$this->journey->journeys->$jkey->segments->$segkey->stats->end = $end;
			$this->journey->journeys->$jkey->segments->$segkey->stats->journeyDuration = $duration;

			// Increase the track duration by the time of our segment
			$this->journey->journeys->$jkey->stats->journeyDuration = $this->journey->journeys->$jkey->stats->journeyDuration + $duration;
			$this->trackduration = $this->trackduration + $this->journey->journeys->$jkey->stats->journeyDuration;

			// We only need to add the min/max times to the track as we've already sorted the segment
			$this->ftimes[] = $this->journey->journeys->$jkey->segments->$segkey->stats->start;
			$this->ftimes[] = $this->journey->journeys->$jkey->segments->$segkey->stats->end;
		}



		// Update the indexes
		$this->tracks[$jkey]['segments'][$segkey] = $x++;
		$this->journey->stats->segments++;

	}


	/** Write stats for the current track
	*
	*/
	function writeTrackStats($jkey){

		// If speed is suppressed we'll have pushed 1 into the array for each trackpart.
		$ptcount = count($this->fspeed);

		if (!$this->suppressspeed){
			$sumspeed = array_sum($this->fspeed);
			$modesearch = array_count_values($this->fspeed); 
			$this->journeyspeeds = array_merge($this->journeyspeeds,$this->fspeed);
			$this->journey->journeys->$jkey->stats->maxSpeed = max($this->fspeed);
			$this->journey->journeys->$jkey->stats->minSpeed = min($this->fspeed);			
			$this->journey->journeys->$jkey->stats->modalSpeed = array_search(max($modesearch), $modesearch);
			$this->journey->journeys->$jkey->stats->avgspeed = round($sumspeed/$ptcount,2);	

			// Add the calculated max/min speeds to the Journey wide stats
			$this->highspeeds[] = $this->journey->journeys->$jkey->stats->maxSpeed;
			$this->lowspeeds[] = $this->journey->journeys->$jkey->stats->minSpeed;
		}

		if (!$this->suppressdate){
			// Finalise the track stats
			$this->journey->journeys->$jkey->stats->start = min($this->ftimes);
			$this->journey->journeys->$jkey->stats->end = max($this->ftimes);

			$this->journey->journeys->$jkey->stats->recordedDuration = $this->trackduration;

			// Update the object times
			$this->totaltimes[] = $this->journey->journeys->$jkey->stats->start;
			$this->totaltimes[] = $this->journey->journeys->$jkey->stats->end;
			$this->journey->stats->recordedDuration = $this->journey->stats->recordedDuration + $this->trackduration;
		}

		// Update the object totals
		$this->journey->stats->trackpoints = $this->journey->stats->trackpoints + $ptcount;
		$this->journey->stats->tracks++;
	}



	/** Reset the track stats counter
	*
	*/
	function resetTrackStats(){
		$this->ftimes = array();
		$this->fspeed = array();
		$this->trackduration = 0;
	}


	/** Reset the segments stats counter
	*
	*/
	function resetSegmentStats(){
		$this->speed = 0;
		$this->sspeed = array();
	}







	/**               ---- Information Retrieval functions ----                     **/



	/** Get the Journey object's metadata
	*
	* @return stdClass
	*
	*/
	function getMetadata(){
		return $this->journey->metadata;
	}



	/** Get the ID's of any tracks that have been ingested
	*
	* @return array
	*
	*/
	function getTrackIDs(){
		return array_keys($this->tracks);
	}


	/** Get ID's and names of any ingested tracks
	*
	* @return array
	*
	*/
	function getTrackNames(){
		$tracks = array();

		foreach ($this->tracks as $k => $v){
			$tracks[] = array('id'=>$k,'name'=>$v['name']);
		}

		return $tracks;
	}


	/** Get details of segments for a given track
	*
	* @arg track - the track ID
	*
	* @return array
	*
	*/
	function getTrackSegmentNames($track){
		return array_keys($this->tracks[$track]['segments']);
	}



	/** Get the trackpoint ID's
	*
	* @arg track - the Track ID
	* @arg segment - the segment ID
	*
	*/
	function getTrackPointNames($track,$segment){
		return array_keys((array) $this->journey->journeys->$track->segments->$segment->points);
	}





	/** Get the time the original GPX file was created
	*
	* @return INT - UNIX Epoch
	*
	*/
	function getGPXTime(){
		return $this->journey->created->time;
	}



	/** Get the timezone used when the JSON object was encoded - previous user may not have set to UTC
	*
	* @return string
	*
	*/
	function getGPXTimeZone(){
		return $this->journey->timezone;
	}




	/**                  ----    Statistics retrieval  ----                      */




	/** Get a count of the recorded track points for a given session
	*
	* @arg track - the track ID
	* @arg segment - the segment ID
	*
	* @return INT
	*
	*/
	function getTrackPointCount($track,$segment){
		return $this->tracks[$track]['segments'][$segment];
	}


	/** Get the overall statistics 
	*
	* @return object
	*/
	function getJourneyStats(){
		return $this->journey->stats;
	}



	/** Get the overall average speed
	*
	* @return decimal
	*
	*/
	function getTotalAvgSpeed(){
		return $this->journey->stats->avgspeed;
	}




	/** Get the stats object from either a track or a segment
	*
	* @arg track - the Track ID
	* @arg segment - the segment ID
	*
	*/
	function getStats($track,$segment=false){
		if (!$segment){
			return $this->journey->journeys->$track->stats;
		}

		return $this->journey->journeys->$track->segments->$segment->stats;

	}



	/** Get the Journey start time
	*
	* @return INT - UNIX epoch
	*
	*/
	function getJourneyStart(){
		return $this->journey->stats->start;
	}


	

	/** Get the Journey end time
	*
	* @return INT - UNIX epoch
	*
	*/
	function getJourneyEnd(){
		return $this->journey->stats->end;
	}




	/** Get the average speed recorded for a track (or a segment within that track)
	*
	* @arg track - the Track ID
	* @arg segment - the segment ID
	*
	*/
	function getAvgSpeed($track,$segment=false){

		if (!$segment){
			return $this->journey->journeys->$track->stats->avgspeed;
		}

		return $this->journey->journeys->$track->segments->$segment->stats->avgspeed;

	}






	/**                     ----   OUTPUT FUNCTIONS   ----                   **/




	/** Retrieve a track object
	*
	* @arg track - the Track ID
	*
	* @return object
	*/
	function getTrack($track){
		return $this->journey->journeys->$track;
	}




	/** Retrieve a segment object
	*
	* @arg track - the Track ID
	* @arg segment - the segment ID
	*
	* @return object
	*/
	function getSegment($track,$segment){
		return $this->journey->journeys->$track->segments->$segment;
	}





	/** Retrieve a trackpoint object
	*
	* @arg track - the Track ID
	* @arg segment - the segment ID
	* @arg trackpoint - the trackpoint ID
	*
	*/
	function getTrackPoint($track,$segment,$point){
		return $this->journey->journeys->$track->segments->$segment->points->$point;
	}



	/** Get the generated object in JSON encapsulated format
	*
	* @return string
	*
	*/
	function getJSON(){
		return json_encode($this->journey);
	}




	/** Get the journey object
	*
	* @return object
	*
	*/
	function getObject(){
		return $this->journey;
	}



	/**           ----   Suppression Functions    ----    */

	/** Suppress elements of the data
	*
	*/
	function suppress($ele){

		switch($ele){
			case 'location':
				$this->suppresslocation = true;
			break;

			case 'speed':
				$this->suppressspeed = true;
			break;

			case 'elevation':
				$this->suppresselevation = true;
			break;

			case 'date':
				$this->suppressdate = true;
			break;

		}
	}



	/** Unsuppress elements of the data
	*
	*/
	function unsuppress($ele){

		switch($ele){
			case 'location':
				$this->suppresslocation = false;
			break;

			case 'speed':
				$this->suppressspeed = false;
			break;

			case 'elevation':
				$this->suppresselevation = false;
			break;

			case 'date':
				$this->suppressdate = false;
			break;

		}
	}


}
