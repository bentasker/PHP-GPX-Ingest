<?php
/** GPXIngest Class
*
* Class to Ingest a basic GPX file, generate stats, convert it into an object and allow some basic manipulation.
* After ingest, the object can be output as JSON for easy storage with stats intact.
*
* @copyright (C) 2013 B Tasker (http://www.bentasker.co.uk). All rights reserved
* @license GNU GPL V2 - See LICENSE
*
* @version 1.1
*
*/

class GPXIngest{

	var $file;
	var $xml;
	var $journey;
	var $tracks = array();



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
	}


	/** Load an XML string
	*
	* @arg $str - XML String
	*
	*/
	function loadString($str){
		$this->xml = simplexml_load_string($str);
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
		$totaltimes = array();
		$highspeeds = array();
		$journeyspeeds = array();
		$lowspeeds = array();
		

		// Add the metadata
		$this->journey->created->creator = (string) $this->xml['creator'];
		$this->journey->created->version = (string) $this->xml['version'];
		$this->journey->created->format = 'GPX';
		$this->journey->created->time = strtotime($this->xml->time);
		$this->journey->timezone = date_default_timezone_get();

	
		$a = 0;

		// There may be multiple tracks in one file
		foreach ($this->xml->trk as $trk){

			// Initialise the stats variables
			$ftimes = array();
			$fspeed = array();
			$trackduration = 0;
			$b = 0;

			// Set the object key
			$jkey = "journey$a";

			// Initialise the object
			$this->journey->journeys->$jkey = new stdClass();
			$this->journey->journeys->$jkey->segments = new stdClass();
			$this->journey->journeys->$jkey->name = (string) $trk->name;
			$this->journey->journeys->$jkey->stats->journeyDuration = 0;

			// Update our index array
			$this->tracks[$jkey]['name'] = $this->journey->journeys->$jkey->name;
			$this->tracks[$jkey]['segments'] = array();
			
			// There may be multiple segments if GPS connectivity was lost - process each seperately
			foreach ($trk->trkseg as $trkseg){

				// Initialise the sub-stats variable
				$speed = 0;
				$x = 0;

				// Set the segment key
				$segkey = "seg$b";

				// Initialise the segment object
				$this->journey->journeys->$jkey->segments->$segkey = new stdClass();
				
				// Trackpoint details in trk - Push them into our object
				foreach ($trkseg->trkpt as $trkpt){

					// Initialise some variables
					$key = "trackpt$x";

					$time = strtotime($trkpt->time);
					$this->journey->journeys->$jkey->segments->$segkey->points->$key->lat = (string) $trkpt['lat'];
					$this->journey->journeys->$jkey->segments->$segkey->points->$key->lon = (string) $trkpt['lon'];
					$this->journey->journeys->$jkey->segments->$segkey->points->$key->time = $time;
					$this->journey->journeys->$jkey->segments->$segkey->points->$key->speed = (string) $trkpt->desc;
					$this->journey->journeys->$jkey->segments->$segkey->points->$key->elevation = (string) $trkpt->ele;

					// Calculate speed stats
					$ptspeed = (int)filter_var($trkpt->desc, FILTER_SANITIZE_NUMBER_INT);
					$speed = $speed + $ptspeed;
					$fspeed[] = $ptspeed;

					// Update the times arrays
					$times[] = $time;
					

					// Up the counters
					$x++;
				}

				

				// Add the segment stats to the journey object
				$start = min($times);
				$end = max($times);
				$duration = $end - $start;

				$this->journey->journeys->$jkey->segments->$segkey->stats->avgspeed = round($speed/$x,2);
				$this->journey->journeys->$jkey->segments->$segkey->stats->start = $start;
				$this->journey->journeys->$jkey->segments->$segkey->stats->end = $end;
				$this->journey->journeys->$jkey->segments->$segkey->stats->journeyDuration = $duration;

				// Increase the track duration by the time of our segment
				$this->journey->journeys->$jkey->stats->journeyDuration = $this->journey->journeys->$jkey->stats->journeyDuration + $duration;
				$trackduration = $trackduration + $this->journey->journeys->$jkey->stats->journeyDuration;

				// Update the index
				$this->tracks[$jkey]['segments'][$segkey] = $x++;

				// We only need to add the min/max times to the track as we've already sorted the segment
				$ftimes[] = $this->journey->journeys->$jkey->segments->$segkey->stats->start;
				$ftimes[] = $this->journey->journeys->$jkey->segments->$segkey->stats->end;
				$this->journey->stats->segments++;
				$b++;
				
			}

			$sumspeed = array_sum($fspeed);
			$ptcount = count($fspeed);
			$modesearch = array_count_values($fspeed); 
			$journeyspeeds = array_merge($journeyspeeds,$fspeed);


			// Finalise the track stats
			$this->journey->journeys->$jkey->stats->start = min($ftimes);
			$this->journey->journeys->$jkey->stats->end = max($ftimes);
			$this->journey->journeys->$jkey->stats->avgspeed = round($sumspeed/$ptcount,2);	
			$this->journey->journeys->$jkey->stats->recordedDuration = $trackduration;
			$this->journey->journeys->$jkey->stats->maxSpeed = max($fspeed);
			$this->journey->journeys->$jkey->stats->minSpeed = min($fspeed);			
			$this->journey->journeys->$jkey->stats->modalSpeed = array_search(max($modesearch), $modesearch);


			// Add the calculated max/min speeds to the Journey wide stats
			$highspeeds[] = $this->journey->journeys->$jkey->stats->maxSpeed;
			$lowspeeds[] = $this->journey->journeys->$jkey->stats->minSpeed;


			// Update the object totals
			$this->journey->stats->trackpoints = $this->journey->stats->trackpoints + $ptcount;
			$this->journey->stats->recordedDuration = $this->journey->stats->recordedDuration + $trackduration;
			
			$this->journey->stats->tracks++;
			$totaltimes[] = $this->journey->journeys->$jkey->stats->start;
			$totaltimes[] = $this->journey->journeys->$jkey->stats->end;

		}

		$modesearch = array_count_values($journeyspeeds);
		

		// Finalise the object stats
		$this->journey->stats->start = min($totaltimes);
		$this->journey->stats->end = max($totaltimes);
		$this->journey->stats->maxSpeed = max($highspeeds);
		$this->journey->stats->minSpeed = min($lowspeeds);
		$this->journey->stats->modalSpeed = array_search(max($modesearch),$modesearch);
		$this->journey->stats->avgspeed = round(array_sum($journeyspeeds) / $this->journey->stats->trackpoints,2);

		// XML Ingest and conversion done!
	}




	/**               ---- Information Retrieval functions ----                     **/



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


}
