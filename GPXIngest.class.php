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
* Where issue keys are included (GPXIN-[0-9]+), the relevant issue can be viewed at http://projects.bentasker.co.uk/jira_projects/browse/GPXIN.html
*/

class GPXIngest{

	private $file;
	private $xml;
	private $journey;
	private $tracks = array();
	private $highspeeds;
	private $lowspeeds;
	private $journeyspeeds;
	private $totaltimes;
	private $ftimes;
	private $trackduration;
	private $smarttrack=true;
	private $smarttrackthreshold = 3600;
	private $suppresslocation = false;
	private $suppressspeed = false;
	private $suppresselevation = false;
	private $suppressdate = false;
	private $lastspeed = false;
	private $lastspeedm = false;
	private $journeylats;
	private $journeylons;
	private $segmentlats;
	private $segmentlons;
	private $tracklats;
	private $tracklons;
	private $ingest_version = 1.02;
	private $entryperiod = 0;
	private $experimentalFeatures = array('calcDistance'); // See GPXIN-17
	private $featuretoggle = array();
	private $waypoints;



	/** Standard constructor
	*
	*/
	function __construct(){
		$this->journey = new stdClass();
		$this->journey->related = new stdClass();
		$this->journey->related->waypoints = new stdClass();
		$this->journey->related->waypoints->points = array();
	}


	/** Reset all relevant class variables - saves unloading the class if processing multiple files
	*
	*/
	public function reset(){
		$this->journey = new stdClass();
		$this->tracks = array();
	}


	/** Load XML from a file
	*
	* @arg $file - string to XML file, can be relative or full path
	*
	*/
	public function loadFile($file){
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
	public function loadString($str){
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
	public function loadJSON($json){
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
	public function loadJSONFile($file){
		return $this->loadJSON(file_get_contents($file));
	}



	/** Toggle SmartTrack on/off
	*
	*/
	public function toggleSmartTrack(){
		$this->smarttrack = ($this->smarttrack)? false : true;
		return $this->smarttrack;
	}



	/** Get the current Smart Track Status
	*
	*/
	public function smartTrackStatus(){
		return $this->smarttrack;
	}


	/** Get the smartTrackThreshold
	*
	*/
	public function smartTrackThreshold(){
		return $this->smarttrackthreshold;
	}



	/** Set the smart Track trigger threshold
	*
	*/
	public function setSmartTrackThreshold($thresh){
		$this->smarttrackthreshold = $thresh;
	}





	/** Ingest the XML and convert into an object
	* 
	* Also updates our reference arrays
	*
	*/
	public function ingest(){

		if (!is_object($this->xml)){
			return false;
		}

		if (!is_object($this->journey)){
		      $this->journey = new stdClass();
		}

		// Initialise the object
		$this->journey->created = new stdClass();
		$this->journey->stats = new stdClass();
		$this->journey->stats->trackpoints = 0;
		$this->journey->stats->recordedDuration = 0;
		$this->journey->stats->segments = 0;
		$this->journey->stats->tracks = 0;
		$this->journey->stats->maxacceleration = 0;
		$this->journey->stats->maxdeceleration = 0;
		$this->journey->stats->minacceleration = 0;
		$this->journey->stats->mindeceleration = 0;
		$this->journey->stats->avgacceleration = 0;
		$this->journey->stats->avgdeceleration = 0;
		$this->journey->stats->speedUoM = array();
		$this->journey->stats->timeMoving = 0;
		$this->journey->stats->timeStationary = 0;
		$this->journey->stats->timeAccelerating = 0;
		$this->journey->stats->timeDecelerating = 0;
		$this->journey->stats->distanceTravelled = 0;

		// Bounds introduced in GPXIN-26
		$this->journey->stats->bounds = new stdClass();
		$this->journey->stats->bounds->Lat = new stdClass();
		$this->journey->stats->bounds->Lat->min = 0;
		$this->journey->stats->bounds->Lat->max = 0;
		$this->journey->stats->bounds->Lon = new stdClass();
		$this->journey->stats->bounds->Lon->min = 0;
		$this->journey->stats->bounds->Lon->max = 0;



		// Initialise the stats array
		$this->totaltimes = array();
		$this->highspeeds = array();
		$this->journeyspeeds = array();
		$this->lowspeeds = array();
		$this->accels = array();
		$this->decels = array();
		$this->jeles = array();
		$this->jeledevs = array();
		$this->jdist = array(); //GPXIN-6
        	$this->journeylats = array(); //GPXIN-26
        	$this->journeylons = array(); //GPXIN-26

		$unit = null;
		

		// Add the metadata
		$this->journey->created->creator = (string) $this->xml['creator'];
		$this->journey->created->version = (string) $this->xml['version'];
		$this->journey->created->format = 'GPX';
		$this->journey->created->namespaces = $this->xml->getNamespaces(true);


		if (!$this->suppressdate && isset($this->xml->time)){
			$this->journey->created->time = strtotime($this->xml->time);
		}

		$this->journey->timezone = date_default_timezone_get();

		// Create the GPXIngest Metadata object
		$this->journey->metadata = new stdClass();
	        $this->journey->metadata->AutoCalc = array('speed'=>false);
		$this->journey->metadata->waypoints = 0; //GPXIN-24
		$trackcounter = 0;

		// There may be multiple tracks in one file
		foreach ($this->xml->trk as $trk){

			// Initialise the stats variables
			$this->resetTrackStats();
			$b = 0;

			// Set the object key
			//$jkey = "journey$a";
			$jkey = $this->genTrackKey($trackcounter);
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
				$times = array();
				$lastele = false;
				$timemoving = 0;
				$timestationary = 0;


				// Set the segment key
				$segkey = $this->genSegKey($b);


				// Initialise the segment object
				$this->initSegment($jkey,$segkey);
				

				// Trackpoint details in trk - Push them into our object
				foreach ($trkseg->trkpt as $trkpt){

					// Initialise some variables
					$key = "trackpt$x";
					$ptspeed = (int)filter_var($trkpt->desc, FILTER_SANITIZE_NUMBER_INT);
					$speed_string = (string) $trkpt->desc;

					// If speed is not available (and we're not calculating anything which can be used) suppress speed
					// Will need updating in GPXIN-20
					if (!$trkpt->desc && !$this->expisenabled('calcDistance')){
					  $this->suppress('speed'); // Prevent warnings if speed is not available - See GPXIN-16
					}

					$time = strtotime($trkpt->time);

					// If smarttrack is enabled, check the trackpt time difference
					if ($this->smarttrack && $this->lasttimestamp && ($this->entryperiod > $this->smarttrackthreshold)){

						// We need to start a new track, but first we have to finalise the stats
						// We use unit, timemoving and stationary from the previous iteration
						$this->writeSegmentStats($jkey,$segkey,$times,$x,$unit,$timemoving,$timestationary);
						$this->writeTrackStats($jkey);
						
						// Reset the segment counter
						$b=0;

						// Update the track counter
						$trackcounter++;

						// Reset the Key
						$x=0;
						$key = "trackpt$x";
						$times = array();

						// Get a new track key
						$jkey = $this->genTrackKey($trackcounter);
						$this->initTrack($jkey,$trk->name.$trackcounter);


						// Get a new segment key
						$segkey = $this->genSegKey($b);
						$this->initSegment($jkey,$segkey);
						
						// Reset stats
						$this->resetTrackStats();
						$this->resetSegmentStats();

					}

					if (!isset($this->journey->journeys->$jkey->segments->$segkey->points)){
					      $this->journey->journeys->$jkey->segments->$segkey->points = new stdClass();
					}

					$this->journey->journeys->$jkey->segments->$segkey->points->$key = new stdClass();

					// Handle Extensions (GPXIN-20)
					$this->journey->journeys->$jkey->segments->$segkey->points->$key->extensions = new stdClass();

					if (isset($trkpt->extensions)){
					      foreach ($this->journey->created->namespaces as $ns=>$nsuri){
						    if (empty($ns)){
							continue;
						    }
						    $ext = array();
						    foreach ($trkpt->extensions->children($nsuri) as $t){
							  $ext[$t->getName()] = (string)$t;
						    }
						    $this->journey->journeys->$jkey->segments->$segkey->points->$key->extensions->$ns = $ext;
					      }
					}

					// Calculate the period to which this trackpoint relates
					if ($this->lasttimestamp){
						$this->entryperiod = $time - $this->lasttimestamp;
					}
	


					// Write the track data - take into account whether we've suppressed any data elements
					if (!$this->suppresslocation){
						$lat = (string) $trkpt['lat']; // let's only caste once
						$lon = (string) $trkpt['lon'];

						$this->journey->journeys->$jkey->segments->$segkey->points->$key->lat = $lat;
						$this->journey->journeys->$jkey->segments->$segkey->points->$key->lon = $lon;

						/** Implemented in GPXIN-6 - currently experimental so will generally be 0 */
						$dist = ($this->lastpos)? $this->calculateTravelledDistance($this->lastpos,array($lat,$lon)) : 0;

						// Added for GPXIN-13
						if (!$trkpt->desc || empty($speed_string)){
						      // Calculate the speed based on distance travelled and time
						      // distance is in feet

						      // Avoid div by 0
						      if ($this->entryperiod == 0){
							    $speed_string = "0 MPH";
							    $ptspeed = 0;
						      }else{						    
							    $fps = $dist / $this->entryperiod; // Feet per second
							    $mph = round(($fps * 0.681818),0);
							    $speed_string = "$mph MPH";
							    $ptspeed = (int)$mph;
						      }
						      // Make sure the metadata shows we did this calculation
						      $this->journey->metadata->AutoCalc['speed'] = true;
						}


						$this->lastpos = array($lat,$lon); // update the reference array

						// Update the stats arrays
						$this->fdist[] = $dist;
						$this->sdist[] = $dist;
						$this->jdist[] = $dist;

						// Update the arrays used for bounds calculations
						$this->journeylats[] = $lat;
						$this->journeylons[] = $lon;
						$this->segmentlats[] = $lat;
						$this->segmentlons[] = $lon;
						$this->tracklats[] = $lat;
						$this->tracklons[] = $lon;

					}


					if ($this->lasttimestamp){
						// Calculate time moving/stationary etc
						if ($this->lastspeed){

							if ($ptspeed > 0){
								$timemoving = $timemoving + $this->entryperiod;
							}else{
								$timestationary = $timestationary + $this->entryperiod;
							}

						}

					}


					if (!$this->suppresselevation){
						$ele = (string) $trkpt->ele;
						$this->journey->journeys->$jkey->segments->$segkey->points->$key->elevation = $ele;

						$change = 0;
						if ($lastele){
							$change = $ele - $lastele;
						}

						$this->journey->journeys->$jkey->segments->$segkey->points->$key->elevationChange = $change;
						
						// Update the stats arrays - should be able to make this more efficient later
						$this->jeles[] = $ele;
						$this->seles[] = $ele;
						$this->feles[] = $ele;

						$this->jeledevs[] = $change;
						$this->seledevs[] = $change;
						$this->feledevs[] = $change;
						

						// Update the elevation for the next time round the block	
						$lastele = $ele;
					}

					if (!$this->suppressdate){
						$this->journey->journeys->$jkey->segments->$segkey->points->$key->time = $time;
						// Update the times arrays
						$times[] = $time;

						// update lasttime - used by SmartTrack
						$lasttime = $time;
					}


					if (!$this->suppressspeed){

						// What is the speed recorded in?
						$unit = strtolower(substr(rtrim($speed_string),strlen($speed_string)-3));


						$this->journey->journeys->$jkey->segments->$segkey->points->$key->speed = $speed_string;
						$this->journey->journeys->$jkey->segments->$segkey->points->$key->speedint = $ptspeed;

						// Calculate speed stats
						$this->speed = $this->speed + $ptspeed;
						$this->fspeed[] = $ptspeed;
						$this->sspeed[] = $ptspeed;


						// Calculate acceleration
						list($acc,$decc) = $this->calculateAcceleration($ptspeed,$time,$unit);
						$this->journey->journeys->$jkey->segments->$segkey->points->$key->acceleration = $acc;
						$this->journey->journeys->$jkey->segments->$segkey->points->$key->deceleration = $decc;

						// There shouldn't usually be more than one UoM per track file, but you never know - that's why it's an array
						if (!in_array($unit,$this->journey->stats->speedUoM)){
							$this->journey->stats->speedUoM[] = $unit;
						}

						// Tracks may also, plausibly, contain more than one measurement
						if (!in_array($unit,$this->journey->journeys->$jkey->stats->speedUoM)){
							$this->journey->journeys->$jkey->stats->speedUoM[] = $unit;
						}

						// If there's more than one unit per segment on the other hand, something's wrong!
						


					}else{
						// We also use the speed array to identify the number of trackpoints
						$this->fspeed[] = 1;
						$unit = null;
					}


					// Set our values for the next run
					$this->lasttimestamp = $time;
					$this->lastspeed = $ptspeed;

					// Up the counters
					$x++;
				}

				$this->writeSegmentStats($jkey,$segkey,$times,$x,$unit,$timemoving,$timestationary);
				$b++;
				
			}


			$this->writeTrackStats($jkey);
			$trackcounter++; # Increment the track counter

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
			$this->journey->stats->maxacceleration = max($this->accels);
			$this->journey->stats->maxdeceleration = max($this->decels);
			$this->journey->stats->minacceleration = min($this->accels);
			$this->journey->stats->mindeceleration = min($this->decels);
			$this->journey->stats->avgacceleration = round(array_sum($this->accels)/count($this->accels),2);
			$this->journey->stats->avgdeceleration = round(array_sum($this->decels)/count($this->accels),2);

		}

		if (!$this->suppresselevation){
			$this->journey->journeys->$jkey->segments->$segkey->stats->elevation = new stdClass();
			$this->journey->journeys->$jkey->segments->$segkey->stats->elevation->max = max($this->jeles);
			$this->journey->journeys->$jkey->segments->$segkey->stats->elevation->min = min($this->jeles);
			$this->journey->journeys->$jkey->segments->$segkey->stats->elevation->avgChange = round(array_sum($this->jeledevs)/count($this->jeledevs),2);
		}

		if (!$this->suppresslocation){
			$this->journey->stats->distanceTravelled = array_sum($this->jdist); // See GPXIN-6

			// Update the Lat/Lon bounds. See GPXIN-26
			$this->journey->stats->bounds->Lat->min = min($this->journeylats);
			$this->journey->stats->bounds->Lat->max = max($this->journeylats);
			$this->journey->stats->bounds->Lon->min = min($this->journeylons);
			$this->journey->stats->bounds->Lon->max = max($this->journeylons);
		}

		// Waypoint support - added in GPXIN-24
		foreach ($this->xml->wpt as $wpt){
			// Increment the counter
			$this->journey->metadata->waypoints++;
			$this->journey->related->waypoints->points[] = $this->buildWptType($wpt);
		}


		// Add any relevant metadata
		$this->journey->metadata->smartTrackStatus = ($this->smartTrackStatus())? 'enabled' : 'disabled';
		$this->journey->metadata->smartTrackThreshold = $this->smartTrackThreshold();
		$this->journey->metadata->suppression = array();
		// Add a version number so the object can be used to identify which stats will/won't be present
		$this->journey->metadata->GPXIngestVersion = $this->ingest_version;

		// Add a list of the supported experimental features and whether they were enabled
		$this->journey->metadata->experimentalFeatureState = $this->listExperimental();

		$this->writeSuppressionMetadata();

		// XML Ingest and conversion done!
	}


	/** Builds a stdClass based around the GPX spec's wptType
	*
	* Despite the name, this type is used for route points as well as waypoints
	*
	* @arg - route/waypoint extracted from XML
	*
	* @return stdclass
	*/
	private function buildWptType($wpt){
		$waypoint = new stdClass();
		$waypoint->name = ($wpt->name)? (string) $wpt->name : null; 
		$waypoint->description = ($wpt->desc)? (string) $wpt->desc : null;
		$waypoint->comment = ($wpt->cmt)? (string) $wpt->cmt : null;


		// Add the positioning information
		$waypoint->position = new stdClass();
		$waypoint->position->lat = ($wpt['lat'])? (string) $wpt['lat'] : null; 
		$waypoint->position->lon = ($wpt['lon'])? (string) $wpt['lon'] : null;
		$waypoint->position->ele = ($wpt->ele)? (string) $wpt->ele : null;
		$waypoint->position->geoidheight = ($wpt->geoidheight)? (string) $wpt->geoidheight : null;

		// Add meta information about the waypoint
		$waypoint->meta = new stdClass();
		$waypoint->meta->time = ($wpt->time)? strtotime($wpt->time) : null;
		$waypoint->meta->magvar = ($wpt->magvar)? (string) $wpt->magvar : null;
		$waypoint->meta->source = ($wpt->src)? (string) $wpt->src : null;
		$waypoint->meta->link = ($wpt->link)? (string) $wpt->link : null;
		$waypoint->meta->symbol = ($wpt->sym)? (string) $wpt->sym : null;
		$waypoint->meta->type = ($wpt->type)? (string) $wpt->type : null;

		// Add the GPS related metadata
		$waypoint->meta->GPS = new stdClass();
		$waypoint->meta->GPS->fix = ($wpt->fix)? (string) $wpt->fix : null;
		$waypoint->meta->GPS->sat = ($wpt->sat)? (int)$wpt->sat : null;
		$waypoint->meta->GPS->hdop = ($wpt->hdop)? (string)$wpt->hdop : null;
		$waypoint->meta->GPS->vdop = ($wpt->vdop)? (string)$wpt->vdop : null;
		$waypoint->meta->GPS->pdop = ($wpt->pdop)? (string)$wpt->pdop : null;
		$waypoint->meta->GPS->ageofdgpsdata = ($wpt->pdop)? (string)$wpt->ageofdgpsdata : null;
		$waypoint->meta->GPS->dgpsid = ($wpt->dgpsid)? (string)$wpt->dgpsid : null;

		// Extension support will come later
		return $waypoint;
	}


	/** Calculate the rate of (ac|de)celeration and update the relevant stats arrays. 
	* Also returns the values
	*
	* All returned values should be considered m/s^2 (i.e. the standard instrument)
	*
	* @arg - Speed (not including Unit -i.e. 1 not 1 KPH or MPH)
	* @arg - timestamp of the speed recording
	* @arg - Unit of measurement (i.e. kph)
	*
	* @return array - acceleration and deceleration.
	*
	*/
	private function calculateAcceleration($speed,$timestamp,$unit){
		$acceleration = 0;
		$deceleration = 0;
		
		// We need to convert the speed into metres per sec


		if ($unit == 'kph'){
			// I'm screwed if my logic is wrong here
			// KPH -> m/s = x kph * 1000 = x metres per hour / 3600 = x metres per second

			$speed = ((int)filter_var($speed, FILTER_SANITIZE_NUMBER_INT)* 1000)/3600;
		}else{
			// MPH. 
			// There are 1609.344 metres to a mile, suspect we may need some rounding done on this one
			$speed = ((int)filter_var($speed, FILTER_SANITIZE_NUMBER_INT)* 1609.344)/3600;
		}



		// Can't calculate acceleration if we don't have a previous timestamp or speed. Also don't want to falsely record acc/dec if the speed is the same.
		if (!$this->lasttimestamp || !$this->lastspeedm || $speed == $this->lastspeed){
			$this->lastspeedm = $speed;
			return array($acceleration,$deceleration);
		}



		// We use the formula
		// (fV - iV)/t


		// We'll worry about whether it's accel or decel after doing the maths
		$velocity_change = ($speed - $this->lastspeedm) / $this->entryperiod;

		if ($velocity_change < 0){
			// It's deceleration
			$deceleration = round(($velocity_change*-1),4);
			$this->fdecel[] = $deceleration;
			$this->decels[] = $deceleration;
			$this->timedecel = $this->timedecel + $this->entryperiod;
		}else{
			// It's acceleration
			$acceleration = round($velocity_change,4);
			$this->faccel[] = $acceleration;
			$this->accels[] = $acceleration;

			if ($velocity_change != 0){
				$this->timeaccel = $this->timeaccel + $this->entryperiod;
			}
		}

		
		


		$this->lastspeedm = $speed;
		$this->lasttimestamp = $timestamp;
		return array($acceleration,$deceleration);

	}


	/** Update the Journey object's metadata to include details of what information (if any) was suppressed at ingest
	*
	*/
	private function writeSuppressionMetadata(){

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
	private function genTrackKey($i){
		return "journey$i";
	}



	/** Generate a segment identifier
	*
	*/
	private function genSegKey($i){
		return "seg$i";
	}




	/** Initialise a Segment object
	*
	*/
	private function initSegment($jkey,$segkey){
		$this->journey->journeys->$jkey->segments->$segkey = new stdClass();
		$this->lasttimestamp = false;
		$this->lastpos = false;
		$this->entryperiod = 0;


                $this->segmentlats = array();
                $this->segmentlons = array();
	}



	/** Initialise a track object
	*
	*/
	private function initTrack($jkey,$trk){

		if (!isset($this->journey->journeys)){
		    $this->journey->journeys = new stdClass();
		}

		$this->journey->journeys->$jkey = new stdClass();
		$this->journey->journeys->$jkey->segments = new stdClass();
		$this->journey->journeys->$jkey->stats = new stdClass();
		$this->journey->journeys->$jkey->name = (string) $trk;
		$this->journey->journeys->$jkey->stats->journeyDuration = 0;
		$this->journey->journeys->$jkey->stats->maxacceleration = 0;
		$this->journey->journeys->$jkey->stats->maxdeceleration = 0;
		$this->journey->journeys->$jkey->stats->minacceleration = 0;
		$this->journey->journeys->$jkey->stats->mindeceleration = 0;
		$this->journey->journeys->$jkey->stats->avgacceleration = 0;
		$this->journey->journeys->$jkey->stats->avgdeceleration = 0;
		$this->journey->journeys->$jkey->stats->speedUoM = array();
		$this->journey->journeys->$jkey->stats->timeMoving = 0;
		$this->journey->journeys->$jkey->stats->timeStationary = 0;
		$this->journey->journeys->$jkey->stats->timeAccelerating = 0;
		$this->journey->journeys->$jkey->stats->timeDecelerating = 0;
		$this->journey->journeys->$jkey->stats->distanceTravelled = 0;

                $this->journey->journeys->$jkey->stats->bounds = new stdClass();
                $this->journey->journeys->$jkey->stats->bounds->Lat = new stdClass();
                $this->journey->journeys->$jkey->stats->bounds->Lat->min = 0;
                $this->journey->journeys->$jkey->stats->bounds->Lat->max = 0;
                $this->journey->journeys->$jkey->stats->bounds->Lon = new stdClass();
                $this->journey->journeys->$jkey->stats->bounds->Lon->min = 0;
                $this->journey->journeys->$jkey->stats->bounds->Lon->max = 0;

		$this->tracks[$jkey]['name'] = $this->journey->journeys->$jkey->name;
		$this->tracks[$jkey]['segments'] = array();

		// Used by the Acceleration calculation method
		$this->lasttimestamp = false;

        	$this->tracklats = array(); //GPXIN-26
        	$this->tracklons = array(); //GPXIN-26
	}



	/** Write stats for the current segment
	*
	*/
	private function writeSegmentStats($jkey,$segkey,$times,$x,$uom,$timemoving,$timestationary){

		if (!isset($this->journey->journeys->$jkey->segments->$segkey->stats)){
			$this->journey->journeys->$jkey->segments->$segkey->stats = new stdClass();
		}

		if (!$this->suppressspeed){
			$modesearch = array_count_values($this->sspeed); 
			$this->journey->journeys->$jkey->segments->$segkey->stats->avgspeed = round($this->speed/$x,2);
			$this->journey->journeys->$jkey->segments->$segkey->stats->modalSpeed = array_search(max($modesearch), $modesearch);
			$this->journey->journeys->$jkey->segments->$segkey->stats->minSpeed = min($this->sspeed);
			$this->journey->journeys->$jkey->segments->$segkey->stats->maxSpeed = max($this->sspeed);
			$this->journey->journeys->$jkey->segments->$segkey->stats->speedUoM = $uom;

		}


		// Calculate the total distance travelled (feet)
		if (!$this->suppresslocation){
			$this->journey->journeys->$jkey->segments->$segkey->stats->distanceTravelled = array_sum($this->sdist);
                        $this->journey->journeys->$jkey->segments->$segkey->stats->bounds = new stdClass(); //GPXIN-26
                        $this->journey->journeys->$jkey->segments->$segkey->stats->bounds->Lat = new stdClass(); 
                        $this->journey->journeys->$jkey->segments->$segkey->stats->bounds->Lon = new stdClass();
			$this->journey->journeys->$jkey->segments->$segkey->stats->bounds->Lat->min = min($this->segmentlats);
			$this->journey->journeys->$jkey->segments->$segkey->stats->bounds->Lat->max = max($this->segmentlats);
			$this->journey->journeys->$jkey->segments->$segkey->stats->bounds->Lon->min = min($this->segmentlons);
			$this->journey->journeys->$jkey->segments->$segkey->stats->bounds->Lon->max = max($this->segmentlons);
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

		if (!$this->suppresselevation){
			$this->journey->journeys->$jkey->segments->$segkey->stats->elevation = new stdClass();
			$this->journey->journeys->$jkey->segments->$segkey->stats->elevation->max = max($this->seles);
			$this->journey->journeys->$jkey->segments->$segkey->stats->elevation->min = min($this->seles);
			$this->journey->journeys->$jkey->segments->$segkey->stats->elevation->avgChange = round(array_sum($this->seledevs)/count($this->seledevs),2);
		}

		// Update the stationary/moving stats
		$this->journey->journeys->$jkey->segments->$segkey->stats->timeMoving = $timemoving;
		$this->journey->journeys->$jkey->segments->$segkey->stats->timeStationary = $timestationary;
		$this->journey->journeys->$jkey->stats->timeMoving = $this->journey->journeys->$jkey->stats->timeMoving + $timemoving;
		$this->journey->journeys->$jkey->stats->timeStationary = $this->journey->journeys->$jkey->stats->timeStationary + $timestationary;
		$this->journey->stats->timeMoving = $this->journey->stats->timeMoving + $timemoving;
		$this->journey->stats->timeStationary = $this->journey->stats->timeStationary + $timestationary;


		// Update the accel stats - has to assume you spent the chunk accelerating so may be inaccurate
		$this->journey->journeys->$jkey->segments->$segkey->stats->timeAccelerating = $this->timeaccel;
		$this->journey->journeys->$jkey->segments->$segkey->stats->timeDecelerating = $this->timedecel;
		$this->journey->journeys->$jkey->stats->timeAccelerating = $this->journey->journeys->$jkey->stats->timeAccelerating + $this->timeaccel;
		$this->journey->journeys->$jkey->stats->timeDecelerating = $this->journey->journeys->$jkey->stats->timeDecelerating + $this->timedecel;
		$this->journey->stats->timeAccelerating = $this->journey->stats->timeAccelerating + $this->timeaccel;
		$this->journey->stats->timeDecelerating = $this->journey->stats->timeDecelerating + $this->timedecel;


		// Update the indexes
		$this->tracks[$jkey]['segments'][$segkey] = $x++;
		$this->journey->stats->segments++;

	}


	/** Write stats for the current track
	*
	*/
	private function writeTrackStats($jkey){

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

			$this->journey->journeys->$jkey->stats->maxacceleration = max($this->faccel);
			$this->journey->journeys->$jkey->stats->maxdeceleration = max($this->fdecel);
			$this->journey->journeys->$jkey->stats->minacceleration = min($this->faccel);
			$this->journey->journeys->$jkey->stats->mindeceleration = min($this->fdecel);

			$this->journey->journeys->$jkey->stats->avgacceleration = round(array_sum($this->faccel)/count($this->faccel),2);
			$this->journey->journeys->$jkey->stats->avgdeceleration = round(array_sum($this->fdecel)/count($this->fdecel),2);

			// Add the calculated max/min speeds to the Journey wide stats
			$this->highspeeds[] = $this->journey->journeys->$jkey->stats->maxSpeed;
			$this->lowspeeds[] = $this->journey->journeys->$jkey->stats->minSpeed;
		}

		if (!$this->suppresslocation){
			$this->journey->journeys->$jkey->stats->distanceTravelled = array_sum($this->fdist);

                        $this->journey->journeys->$jkey->stats->bounds = new stdClass(); //GPXIN-26
                        $this->journey->journeys->$jkey->stats->bounds->Lat = new stdClass(); 
                        $this->journey->journeys->$jkey->stats->bounds->Lon = new stdClass();
			$this->journey->journeys->$jkey->stats->bounds->Lat->min = min($this->tracklats);
			$this->journey->journeys->$jkey->stats->bounds->Lat->max = max($this->tracklats);
			$this->journey->journeys->$jkey->stats->bounds->Lon->min = min($this->tracklons);
			$this->journey->journeys->$jkey->stats->bounds->Lon->max = max($this->tracklons);
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

		if (!$this->suppresselevation){
			$this->journey->journeys->$jkey->stats->elevation = new stdClass();
			$this->journey->journeys->$jkey->stats->elevation->max = max($this->feles);
			$this->journey->journeys->$jkey->stats->elevation->min = min($this->feles);
			$this->journey->journeys->$jkey->stats->elevation->avgChange = round(array_sum($this->feledevs)/count($this->feledevs),2);
		}


		// Update the object totals
		$this->journey->stats->trackpoints = $this->journey->stats->trackpoints + $ptcount;
		$this->journey->stats->tracks++;
	}


	/** Calculate the distance between two lat/lon points - see GPXIN-6
	*
	* @arg array - (lat,lon) - the previous position
	* @arg array - (lat,lon) - the current position
	*
	* @return distance travelled (feet)
	*/
	protected function calculateTravelledDistance($old,$new){


	  if (!$this->expisenabled('calcDistance')){ // This functionality is currently considered experimental
		  return 0;
	  }

	  // Array mapping (for ease of reference)
          // lat1 - old[0]
          // lat2 - new[0]
	  // lon1 - old[1]
          // lon2 - new[1]

	  $theta = $old[1] - $new[1];
	  $dist = acos(sin(deg2rad($old[0])) * sin(deg2rad($new[0])) + cos(deg2rad($old[0])) * cos(deg2rad($new[0])) * cos(deg2rad($theta)));
	  $dist = rad2deg($dist);

	  $res = round(($dist * 60 * 1.1515) * 5280,3); // Convert to feet and round to 3 decimal places

	  return (is_nan($res))? 0 : $res;
	}


	/** Reset the track stats counter
	*
	*/
	private function resetTrackStats(){
		$this->ftimes = array();
		$this->fspeed = array();
		$this->trackduration = 0;
		$this->faccel = array();
		$this->fdecel = array();
		$this->feles = array();
		$this->feledevs = array();
		$this->fdist = array();
		$this->tracklats = array();
		$this->tracklons = array();
	}


	/** Reset the segments stats counter
	*
	*/
	private function resetSegmentStats(){
		$this->speed = 0;
		$this->sspeed = array();
		$this->seles = array();
		$this->seledevs = array();
		$this->timeaccel = 0;
		$this->timedecel = 0;
		$this->sdist = array();
	}







	/**               ---- Information Retrieval functions ----                     **/



	/** Get the Journey object's metadata
	*
	* @return stdClass
	*
	*/
	public function getMetadata(){
		return $this->journey->metadata;
	}



	/** Get the ID's of any tracks that have been ingested
	*
	* @return array
	*
	*/
	public function getTrackIDs(){
		return array_keys($this->tracks);
	}


	/** Get ID's and names of any ingested tracks
	*
	* @return array
	*
	*/
	public function getTrackNames(){
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
	public function getTrackSegmentNames($track){
		return array_keys($this->tracks[$track]['segments']);
	}



	/** Get the trackpoint ID's
	*
	* @arg track - the Track ID
	* @arg segment - the segment ID
	*
	*/
	public function getTrackPointNames($track,$segment){
		return array_keys((array) $this->journey->journeys->$track->segments->$segment->points);
	}



	/** Get the XML name of a specific Track
	*
	* @return string
	*/
	public function getTrackName($jkey){
		return $this->journey->journeys->$jkey->name;
	}


	/** Get the time the original GPX file was created
	*
	* @return INT - UNIX Epoch
	*
	*/
	public function getGPXTime(){
		return $this->journey->created->time;
	}



	/** Get the timezone used when the JSON object was encoded - previous user may not have set to UTC
	*
	* @return string
	*
	*/
	public function getGPXTimeZone(){
		return $this->journey->timezone;
	}


	/** Get the XML Namespaces that were defined in the source GPX file
	*
	* @return string
	*
	*/
	public function getGPXNameSpaces(){
		return $this->journey->created->namespaces;
	}


	/** Get any waypoints which were ingested (GPXIN-24)
	*
	* @return array
	*
	*/
	public function getWaypoints(){
		return $this->journey->related->waypoints->points;
	}

	/** Get a waypoint which were ingested (GPXIN-24)
	*
	* @arg INT - Key of the requested waypoing
	*
	* @return stdClass
	*
	*/
	public function getWaypoint($id){
		return $this->journey->related->waypoints->points[$id];
	}

	/**                  ----    Statistics retrieval  ----                      */


	/** Get a count of the recorded way points
	*
	*
	* @return INT
	*
	*/
	public function getWayPointCount(){
		return $this->journey->metadata->waypoints;
	}



	/** Get a count of the recorded track points for a given session
	*
	* @arg track - the track ID
	* @arg segment - the segment ID
	*
	* @return INT
	*
	*/
	public function getTrackPointCount($track,$segment){
		return $this->tracks[$track]['segments'][$segment];
	}


	/** Get the overall statistics 
	*
	* @return object
	*/
	public function getJourneyStats(){
		return $this->journey->stats;
	}



	/** Get the overall average speed
	*
	* @return decimal
	*
	*/
	public function getTotalAvgSpeed(){
		return $this->journey->stats->avgspeed;
	}




	/** Get the stats object from either a track or a segment
	*
	* @arg track - the Track ID
	* @arg segment - the segment ID
	*
	*/
	public function getStats($track,$segment=false){
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
	public function getJourneyStart(){
		return $this->journey->stats->start;
	}


	

	/** Get the Journey end time
	*
	* @return INT - UNIX epoch
	*
	*/
	public function getJourneyEnd(){
		return $this->journey->stats->end;
	}




	/** Get the average speed recorded for a track (or a segment within that track)
	*
	* @arg track - the Track ID
	* @arg segment - the segment ID
	*
	*/
	public function getAvgSpeed($track,$segment=false){

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
	public function getTrack($track){
		return $this->journey->journeys->$track;
	}




	/** Retrieve a segment object
	*
	* @arg track - the Track ID
	* @arg segment - the segment ID
	*
	* @return object
	*/
	public function getSegment($track,$segment){
		return $this->journey->journeys->$track->segments->$segment;
	}





	/** Retrieve a trackpoint object
	*
	* @arg track - the Track ID
	* @arg segment - the segment ID
	* @arg trackpoint - the trackpoint ID
	*
	*/
	public function getTrackPoint($track,$segment,$point){
		return $this->journey->journeys->$track->segments->$segment->points->$point;
	}



	/** Get the generated object in JSON encapsulated format
	*
	* @return string
	*
	*/
	public function getJSON(){
		return json_encode($this->journey);
	}




	/** Get the journey object
	*
	* @return object
	*
	*/
	public function getObject(){
		return $this->journey;
	}



	/**           ----   Suppression Functions    ----    */

	/** Suppress elements of the data
	*
	*/
	public function suppress($ele){

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
	public function unsuppress($ele){

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



	/** Identify whether an experimental feature has been enabled
	*
	* @arg type
	*
	* @return boolean
	*/
	protected function expisenabled($type){
	      return isset($this->featuretoggle[$type]);
	}



	/** Enable functionality which is considered experimental or computationally expensive
	*
	* @arg type - the element to enable
	*
	* @return void
	*/
	public function enableExperimental($type){

		if (in_array($type,$this->experimentalFeatures)){
		      $this->featuretoggle[$type] = 1;
		}
	}



	/** Disable functionality which is considered experimental or computationally expensive
	*
	* @arg type - the element to enable
	*
	* @return void
	*/
	public function disableExperimental($type){
		if (isset($this->featuretoggle[$type])){
		      unset($this->featuretoggle[$type]);
		}
	}



	/** List experimental features and indicate whether they are currently enabled
	*
	*
	* @return array
	*/
	public function listExperimental(){

		$resp = array();

		foreach ($this->experimentalFeatures as $feature){
			$resp[$feature] = (isset($this->featuretoggle[$feature]))? 1 : 0;
		}

		return $resp;
	}

}
