<?
// Ireland PlanningAlerts scraper for Wicklow County Council
// Uses LGMA shared backend for map data at ArcGIS.com
//
// John Handelaar 2017-06-27

require 'scraperwiki.php';
require 'scraperwiki/simple_html_dom.php';

date_default_timezone_set('Europe/Dublin');
$date_format = 'Y-m-d';
$cookie_file = '/tmp/cookies.txt';


# Splitting this for legibility only
$remote_uri = 'https://services3.arcgis.com/vgpNvkwrqKit2cbA/arcgis/rest/services/NationalPlanningApplications_Points' . 
              '/FeatureServer/0/query?f=json&where=((PlanningAuthority%20%3D%20%27Wicklow%20County%20Council%27)' .
              '%20AND%20(ReceivedDate%20BETWEEN%20CURRENT_TIMESTAMP%20-%2030%20AND%20CURRENT_TIMESTAMP))%20AND%20(1%3D1)' .
              '&returnGeometry=true&spatialRel=esriSpatialRelIntersects&outFields=*&orderByFields=OBJECTID%20ASC&' .
              'outSR=4326&resultOffset=0&resultRecordCount=500';

$council_eplan_root_uri = 'http://www.eplanning.ie/WicklowCC/';
$council_comment_url = 'http://www.wicklow.ie/online-enquiries';

/*
 ------------------------------------------------------------
 You should not need to alter this scraper below this point.
 ------------------------------------------------------------
 */


# Get the file contents as JSON
$curl = curl_init($remote_uri);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_USERAGENT, "Mozilla/5.0 (compatible; PlanningAlerts/0.1; +http://www.planningalerts.org/)");
$json_response = curl_exec($curl);
curl_close($curl);


# Get a page from the planning portal and discard it
# Otherwise first attempt to retrieve application by URI will be redirected to home
$curl = curl_init($council_eplan_root_uri);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_COOKIEJAR, $cookie_file);
curl_setopt($curl, CURLOPT_COOKIEFILE, $cookie_file);
$got_cookie = curl_exec($curl);
curl_close($curl);
unset($got_cookie);


# Decode and process the data
$applications = json_decode($json_response);

foreach ($applications->features as $application) {
    echo 'Found: ' . $application->attributes->ApplicationNumber . "\n";
    $council_reference = trim($application->attributes->ApplicationNumber);
    $info_url = trim($application->attributes->LinkAppDetails);
    $comment_url = $council_comment_url;
    $lat = trim($application->geometry->y);
    $lng = trim($application->geometry->x);
    $date_scraped = date($date_format);
    
    # Retrieve this application
    $curl = curl_init($application->attributes->LinkAppDetails);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($curl, CURLOPT_COOKIEFILE, $cookie_file);
    $appdetails = curl_exec($curl);
    curl_close($curl);
    
    $parsethis = new simple_html_dom();
    $parsethis->load($appdetails);
    
    $addresspath = $parsethis->find('#Development table',0)->find('tr',2)->find('td',0);
    $address = trim(html_entity_decode($addresspath->plaintext),ENT_QUOTES);

    $descriptionpath = $parsethis->find('#Development table',0)->find('tr',1)->find('td',0);
    $description = trim(html_entity_decode($descriptionpath->plaintext),ENT_QUOTES);

    $receivedpath = $parsethis->find('#Details table',0)->find('tr',4)->find('td',0);
    $date_received = date($date_format,strtotime(str_replace('/','-',trim($receivedpath->plaintext)))); # Don't use slashes -- US dateformat assumed if you do
    $on_notice_from = $date_received;
    
    $deadlinepath = $parsethis->find('#Details table',0)->find('tr',9)->find('td',1);
    $on_notice_to = date($date_format,strtotime(str_replace('/','-',trim($deadlinepath->plaintext)))); # Don't use slashes -- US dateformat assumed if you do

    $application = array(
        'council_reference' => $council_reference,
        'address' => $address,
        'lat' => $lat,
        'lng' => $lng,
        'description' => $description,
        'info_url' => $info_url,
        'comment_url' => $comment_url,
        'date_scraped' => $date_scraped,
        'date_received' => $date_received,
        'on_notice_from' => $on_notice_from,
        'on_notice_to' => $on_notice_to
    );

    $existingRecords = scraperwiki::select("* from data where `council_reference`='" . $application['council_reference'] . "'");
    if (sizeof($existingRecords) == 0) {
        # print_r ($application);
        scraperwiki::save(array('council_reference'), $application);
    } else {
        print ("Skipping already saved record " . $application['council_reference'] . "\n");
    }
}


?>
