PHP-GPX-Ingest
==============

PHP GPX-Ingest is a simple PHP class designed to ingest a basic Global Position eXchange (GPX) file and extract data/stats from the tracks within. It's been tested with the GPX files output by the Android app SpeedView.

Documentation and example usage can be found in [the class documentation on my website](http://www.bentasker.co.uk/documentation/development-programming/222-php-gpx-ingest).




Features
----------

The class has the following features

 - Import basic GPX files
 - Generate stats for each track, and maintain global stats
 - 'SmartTrack' functionality - if an excessive delay is detected between trackpoints a new track will be started (can be disabled/threshold adjusted)
 - Ability to suppress data - Can prevent speed, location, dates and/or elevation from being included in the resulting Journey object.
 - Auto calculation of distance travelled based on lat/lon changes. See [GPXIN-6](http://projects.bentasker.co.uk/jira_projects/browse/GPXIN-6.html)
 - Auto calculation of speed based on distance travelled, if speed not defined within the GPX file. See [GPXIN-13](http://projects.bentasker.co.uk/jira_projects/browse/GPXIN-13.html)
 - Support for waypoints [GPXIN-24](http://projects.bentasker.co.uk/jira_projects/browse/GPXIN-24.html)
 - Support for Routes [GPXIN-27](http://projects.bentasker.co.uk/jira_projects/browse/GPXIN-27.html)

 

Issue Tracking
----------------

Issue and feature tracking is within a private JIRA instance, a HTML mirror can be viewed at http://projects.bentasker.co.uk/jira_projects/browse/GPXIN.html

If you think you've found a bug, or want to ask a question, send an email to gpxingest **AT** bentasker.co.uk



Copyright
----------

PHP GPX-Ingest is Copyright (C) 2013 B Tasker. All Rights Reserved.
Released under the GNU GPL V2 License, see LICENSE.
