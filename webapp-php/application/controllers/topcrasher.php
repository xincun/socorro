<?php defined('SYSPATH') or die('No direct script access.');

/* ***** BEGIN LICENSE BLOCK *****
 * Version: MPL 1.1/GPL 2.0/LGPL 2.1
 *
 * The contents of this file are subject to the Mozilla Public License Version
 * 1.1 (the "License"); you may not use this file except in compliance with
 * the License. You may obtain a copy of the License at
 * http://www.mozilla.org/MPL/
 *
 * Software distributed under the License is distributed on an "AS IS" basis,
 * WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
 * for the specific language governing rights and limitations under the
 * License.
 *
 * The Original Code is Socorro Crash Reporter
 *
 * The Initial Developer of the Original Code is
 * The Mozilla Foundation.
 * Portions created by the Initial Developer are Copyright (C) 2006
 * the Initial Developer. All Rights Reserved.
 *
 * Contributor(s):
 *   Ryan Snyder <rsnyder@mozilla.com>
 *
 * Alternatively, the contents of this file may be used under the terms of
 * either the GNU General Public License Version 2 or later (the "GPL"), or
 * the GNU Lesser General Public License Version 2.1 or later (the "LGPL"),
 * in which case the provisions of the GPL or the LGPL are applicable instead
 * of those above. If you wish to allow use of your version of this file only
 * under the terms of either the GPL or the LGPL, and not to allow others to
 * use your version of this file under the terms of the MPL, indicate your
 * decision by deleting the provisions above and replace them with the notice
 * and other provisions required by the GPL or the LGPL. If you do not delete
 * the provisions above, a recipient may use your version of this file under
 * the terms of any one of the MPL, the GPL or the LGPL.
 *
 * ***** END LICENSE BLOCK ***** */
 
require_once(Kohana::find_file('libraries', 'bugzilla', TRUE, 'php'));
require_once(Kohana::find_file('libraries', 'Correlation', TRUE, 'php'));
require_once(Kohana::find_file('libraries', 'crash', TRUE, 'php'));
require_once(Kohana::find_file('libraries', 'release', TRUE, 'php'));
require_once(Kohana::find_file('libraries', 'timeutil', TRUE, 'php'));
require_once(Kohana::find_file('libraries', 'versioncompare', TRUE, 'php'));

/**
 * Reports based on top crashing signatures
 */
class Topcrasher_Controller extends Controller {

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->topcrashers_model = new Topcrashers_Model();
        $this->bug_model = new Bug_Model;
    }
    
    /**
     * Handle empty version values in the methods below, and redirect accordingly.
     *
     * @param   string  The product name
     * @param   string  The method name
     * @return  void
     */
    private function _handleEmptyVersion($product, $method) {
        $product_version = $this->branch_model->getRecentProductVersion($product);
        $version = $product_version->version;
        $this->chooseVersion(
            array(
                'product' => $product,
                'version' => $version,
                'release' => null
            )
        );
        url::redirect('topcrasher/'.$method.'/'.$product.'/'.$version);
    }

    /**
     * Generates the index page.
     */
    public function index() {
        cachecontrol::set(array(
            'expires' => time() + (60 * 60)
        ));

        $featured = Kohana::config('dashboard.feat_nav_products');
        $all_products = $this->currentProducts();

        $i = 0;
        $crasher_data = array();
        foreach ($featured as $prod_name) {
            foreach (array(Release::MAJOR, Release::DEVELOPMENT,
                Release::MILESTONE) as $release) {
                if (empty($all_products[$prod_name][$release])) continue;
                if (++$i > 4) break 2;

                $version = $all_products[$prod_name][$release];
                $crasher_data[] = array(
                    'product' => $prod_name,
                    'version' => $version,
                    'crashers' => $this->_getTopCrashers($prod_name, $version)
                    );
            }
        }

        // generate list of all versions
        $branches = new Branch_Model();
        $prod_versions = $branches->getProductVersions();
        $all_versions = array();
        foreach ($prod_versions as $ver) {
            $all_versions[$ver->product][] = $ver->version;
        }
        // sort
        $vc = new VersioncompareComponent();
        foreach (array_keys($all_versions) as $prod) {
            $vc->sortAppversionArray($all_versions[$prod]);
            $all_versions[$prod] = array_reverse($all_versions[$prod]);
        }

        $this->setViewData(array(
            'crasher_data' => $crasher_data,
            'all_versions'  => $all_versions,
    	    'nav_selection' => 'top_crashes',
            'sig_sizes'  => Kohana::config('topcrashers.numberofsignatures'),            
            'url_nav' => url::site('products/'.$product),
        ));
    }

    /**
     * get top crashers for a given product and version
     */
    private function _getTopCrashers($product, $version) {
        $sigSize = Kohana::config("topcrashers.numberofsignatures");
        $maxSigSize = max($sigSize);

        $end = $this->topcrashers_model->lastUpdatedByVersion($product, $version);
        $start = $this->topcrashers_model->timeBeforeOffset(14, $end);

        return $this->topcrashers_model->getTopCrashersByVersion($product, $version, $maxSigSize, $start, $end);
    }

    /**
     * Display the top crashers by product & version.
     *
     * @param   string  The name of the product
     * @param   version The version  number for this product
     * @param   int     The number of days for which to display results
     * @return  void
     */
    public function byversion($product, $version=null, $duration=14)
    {
        if (empty($version)) {
            $this->_handleEmptyVersion($product, 'byversion');
        }
        
	$duration_url_path = array(Router::$controller, Router::$method, $product, $version);
	$other_durations = array_diff(Kohana::config('topcrashbysig.durations'),
				      array($duration));

	$config = array();
	$credentials = Kohana::config('webserviceclient.basic_auth');
	if ($credentials) {
	    $config['basic_auth'] = $credentials;
	}
	$service = new Web_Service($config);

	$host = Kohana::config('webserviceclient.socorro_hostname');

	$cache_in_minutes = Kohana::config('webserviceclient.topcrash_vers_rank_cache_minutes', 60);
	$end_date = urlencode(date('Y-m-d\TH:i:s\T+0000', TimeUtil::roundOffByMinutes($cache_in_minutes)));
	// $dur is number of hours 
	$dur = $duration * 24;
	$limit = Kohana::config('topcrashbysig.byversion_limit', 300);
	// lifetime in seconds
	$lifetime = $cache_in_minutes * 60;

	$p = urlencode($product);
	$v = urlencode($version);
        $resp = $service->get("${host}/200911/topcrash/sig/trend/rank/p/${p}/v/${v}/end/${end_date}/duration/${dur}/listsize/${limit}",
			      'json', $lifetime);
	if($resp) {
	    $this->topcrashers_model->ensureProperties($resp, array(
				     'start_date' => '',
				     'end_date' => '',
				     'totalPercentage' => 0,
				     'crashes' => array(),
				     'totalNumberOfCrashes' => 0), 'top crash sig overall');
	    $signatures = array();
	    $req_props = array( 'signature' => '', 'count' => 0, 
				'win_count' => 0, 'mac_count' => 0, 'linux_count' => 0,
				'currentRank' => 0, 'previousRank' => 0, 'changeInRank' => 0, 
				'percentOfTotal' => 0, 'previousPercentOfTotal' => 0, 'changeInPercentOfTotal' => 0);

	    foreach($resp->crashes as $top_crasher) {
		$this->topcrashers_model->ensureProperties($top_crasher, $req_props, 'top crash sig trend crashes');

		if ($this->input->get('format') != "csv") {
                    //$top_crasher->{'missing_sig_param'} - optional param, used for formating url to /report/list
		    if (is_null($top_crasher->signature)) {
			$top_crasher->{'display_signature'} = Crash::$null_sig;
			$top_crasher->{'display_null_sig_help'} = TRUE;
		        $top_crasher->{'missing_sig_param'} = Crash::$null_sig_code;
		    } else if(empty($top_crasher->signature)) {
			$top_crasher->{'display_signature'} = Crash::$empty_sig;
			$top_crasher->{'display_null_sig_help'} = TRUE;
		        $top_crasher->{'missing_sig_param'} = Crash::$empty_sig_code;
		    } else {
			$top_crasher->{'display_signature'} = $top_crasher->signature;
			$top_crasher->{'display_null_sig_help'} = FALSE;
		    }

		    $top_crasher->{'display_percent'} = number_format($top_crasher->percentOfTotal * 100, 2) . "%";
		    $top_crasher->{'display_previous_percent'} = number_format($top_crasher->previousPercentOfTotal * 100, 2) . "%";
		    $top_crasher->{'display_change_percent'} = number_format($top_crasher->changeInPercentOfTotal * 100, 2) . "%";

		    array_push($signatures, $top_crasher->signature);

                    $top_crasher->{'correlation_os'} = Correlation::correlationOsName($top_crasher->win_count, $top_crasher->mac_count, $top_crasher->linux_count);
		}
		$top_crasher->trendClass = $this->topcrashers_model->addTrendClass($top_crasher->changeInRank);
            }
            $unique_signatures = array_unique($signatures);
	    $rows = $this->bug_model->bugsForSignatures($unique_signatures);
	    $bugzilla = new Bugzilla;
	    $signature_to_bugzilla = $bugzilla->signature2bugzilla($rows, Kohana::config('codebases.bugTrackingUrl'));
 

	    $signature_to_oopp = $this->topcrashers_model->ooppForSignatures($product, $version, $resp->end_date, $duration, $unique_signatures);
	    foreach($resp->crashes as $top_crasher) {
		$hang_details = array();
                $known = array_key_exists($top_crasher->signature, $signature_to_oopp);
		$hang_details['is_hang'] = $known && $signature_to_oopp[$top_crasher->signature]['hang'] == true;
		$hang_details['is_plugin'] = $known && $signature_to_oopp[$top_crasher->signature]['process'] == 'Plugin';
		$top_crasher->{'hang_details'} = $hang_details;
	    }
	    $this->navigationChooseVersion($product, $version);

	    if ($this->input->get('format') == "csv") {
		$this->setViewData(array('top_crashers' => $this->_csvFormatArray($resp->crashes)));
		$this->renderCSV("${product}_${version}_" . date("Y-m-d"));
	    } else {
		$this->setViewData(array(
				       'resp'         => $resp,
				       'duration_url' => url::site(implode($duration_url_path, '/') . '/'),
				       'last_updated' => $resp->end_date,
				       'other_durations' => $other_durations,
				       'percentTotal' => $resp->totalPercentage,
				       'product'      => $product,
				       'version'      => $version,
               	       'nav_selection' => 'top_crashes',
				       'sig2bugs'     => $signature_to_bugzilla,
				       'start'        => $resp->start_date,
				       'end_date'          => $resp->end_date,
				       'top_crashers' => $resp->crashes,
				       'total_crashes' => $resp->totalNumberOfCrashes,
				       'url_nav'     => url::site('products/'.$product),
				       ));
	    }
	} else {
	    header("Data access error", TRUE, 500);
	    $this->setViewData(
	        array(
           	       'nav_selection' => 'top_crashes',
                   'product'        => $product,
                   'url_nav'        => url::site('products/'.$product),
				   'version'      => $version,
				   'resp'         => $resp
			    )
            );
	     }
    }

    /**
     * AJAX request for grabbing crash history data to be plotted
     * @param string - the product
     * @param string - the version
     * @param string - the signature OR $null_sig TODO
	 * @param string	The start date by which to begin the plot
	 * @param string	The end date by which to end the plot
     * @return responds with JSON suitable for plotting
     */
    public function plot_signature($product, $version, $signature, $start_date, $end_date)
    {
	//Bug#532434 Kohana is escaping some characters with html entity encoding for security purposes
	$signature = html_entity_decode($signature);

	header('Content-Type: text/javascript');
	$this->auto_render = FALSE;

	$config = array();
	$credentials = Kohana::config('webserviceclient.basic_auth');
	if ($credentials) {
	    $config['basic_auth'] = $credentials;
	}
	$service = new Web_Service($config);

	$host = Kohana::config('webserviceclient.socorro_hostname');

	$cache_in_minutes = Kohana::config('webserviceclient.topcrash_vers_rank_cache_minutes', 60);
	$start_date = str_replace(" ", "T", $start_date.'+0000', TimeUtil::roundOffByMinutes($cache_in_minutes));
	$end_date = str_replace(" ", "T", $end_date.'+0000', TimeUtil::roundOffByMinutes($cache_in_minutes));
	$duration = TimeUtil::determineHourDifferential($start_date, $end_date); // Number of hours

	$start_date = urlencode($start_date);
	$end_date = urlencode($end_date);

	$limit = Kohana::config('topcrashbysig.byversion_limit', 300);
	$lifetime = $cache_in_minutes * 60; // Lifetime in seconds

	$p = urlencode($product);
	$v = urlencode($version);
	
	//Bug#534063
	if ($signature == Crash::$null_sig) {
	    $signature = Crash::$null_sig_api_value;
        } else if($signature == Crash::$empty_sig) {
	    $signature = Crash::$empty_sig_api_value;
        }
	$rsig = rawurlencode($signature); //NPSWF32.dll%400x136a29
	// Every 3 hours
        $resp = $service->get("${host}/200911/topcrash/sig/trend/history/p/${p}/v/${v}/sig/${rsig}/end/${end_date}/duration/${duration}/steps/60",
			      'json', $lifetime);


	if($resp) {
	    $data = array('startDate' => $resp->{'start_date'},
			  'endDate'   => $resp->{'end_date'},
			  'signature' => $resp->signature,
		          'counts'    => array(),
			  'percents'  => array());
	    for ($i =0; $i < count($resp->signatureHistory); $i++) {

		$item = $resp->signatureHistory[$i];
		array_push($data['counts'], array(strtotime($item->date) * 1000, $item->count));
		array_push($data['percents'], array(strtotime($item->date) * 1000, $item->percentOfTotal * 100));
	    } 
	    echo json_encode($data);
	} else {
	    echo json_encode(array('error' => 'There was an error loading the data'));
	}
    }

    /**
     * Helper method for formatting a topcrashers list of objects into data 
     * suitable for CSV output
     * @param array of topCrashersBySignature object
     * @return array of strings
     * @see Topcrashers_Model
     */
    private function _csvFormatArray($topcrashers)
    {
        $csvData = array(array('Rank', 'Change In Rank', 'Percentage of All Crashes', 
			       'Previous Percentage', 'Signature', 
			       'Total', 'Win', 'Mac', 'Linux'));
	$i = 0;
        foreach ($topcrashers as $crash) {
	    $line = array();
	    $sig = strtr($crash->signature, array(
                    ',' => ' ',
                    '\n' => ' ',
		    '"' => '&quot;'
            ));
	    array_push($line, $i);
	    array_push($line, $crash->changeInRank);
	    array_push($line, $crash->percentOfTotal);
	    array_push($line, $crash->previousPercentOfTotal);
	    array_push($line, $sig);
	    array_push($line, $crash->count);
	    array_push($line, $crash->win_count);
	    array_push($line, $crash->mac_count);
	    array_push($line, $crash->linux_count);
	    array_push($csvData, $line);
	    $i++;
	}
      return $csvData;
    }

    /**
     * Helper method for formatting a topcrashers list of objects into data 
     * suitable for CSV output
     * @param array of topCrashersBySignature object
     * @return array of strings
     * @see Topcrashers_Model
     */
    private function _csvFormatOldArray($topcrashers)
    {
        $csvData = array(array('Rank, Percentage of All Crashes, Signature, Total, Win, Linux, Mac'));
	$i = 0;
        foreach ($topcrashers as $crash) {
	    $line = array();
	    $sig = strtr($crash->signature, array(
                    ',' => ' ',
                    '\n' => ' ',
		    '"' => '&quot;'
            ));
	    array_push($line, $i);
	    array_push($line, $crash->percent);
	    array_push($line, $sig);
	    array_push($line, $crash->total);
	    array_push($line, $crash->win);
	    array_push($line, $crash->mac);
	    array_push($line, $crash->linux);
	    array_push($csvData, $line);
	    $i++;
	}
      return $csvData;
    }

    /**
     * Generates the report based on branch info
     * 
     * @param string branch
     * @param int duration in days that this report should cover
     */
    public function bybranch($branch, $duration = 14) {
	$other_durations = array_diff(Kohana::config('topcrashbysig.durations'),
				      array($duration));
	$limit = Kohana::config('topcrashbysig.bybranch_limit', 100);
	$top_crashers = array();
	$start = "";
        $last_updated = $this->topcrashers_model->lastUpdatedByBranch($branch);

	$percentTotal = 0;
	$totalCrashes = 0;

        $signature_to_bugzilla = array();
	$signatures = array();

	if ($last_updated !== FALSE) {
	    $start = $this->topcrashers_model->timeBeforeOffset($duration, $last_updated);
	    $totalCrashes = $this->topcrashers_model->getTotalCrashesByBranch($branch, $start, $last_updated);
	    if ($totalCrashes > 0) {
		$top_crashers = $this->topcrashers_model->getTopCrashersByBranch($branch, $limit, $start, $last_updated, $totalCrashes);
		for($i=0; $i < count($top_crashers); $i++) {
		    $percentTotal += $top_crashers[$i]->percent;
                    if ($this->input->get('format') != "csv") {
		        $top_crashers[$i]->percent = number_format($top_crashers[$i]->percent * 100, 2) . "%";
			array_push($signatures, $top_crashers[$i]->signature);
		    }
		}
		$rows = $this->bug_model->bugsForSignatures(array_unique($signatures));
		$bugzilla = new Bugzilla;
		$signature_to_bugzilla = $bugzilla->signature2bugzilla($rows, Kohana::config('codebases.bugTrackingUrl'));
	    }
	}
        cachecontrol::set(array(
            'expires' => time() + (60 * 60)
        ));
        if ($this->input->get('format') == "csv") {
  	    $this->setViewData(array('top_crashers' => $this->_csvFormatOldArray($top_crashers)));
  	    $this->renderCSV("${branch}_" . date("Y-m-d"));
	} else {
	    $duration_url_path = array(Router::$controller, Router::$method, $branch, "");
	    $this->setViewData(array(
                'branch'       => $branch,
		'last_updated' => $last_updated, 
		'percentTotal' => $percentTotal,
	    'nav_selection' => 'top_crashes',
		'other_durations' => $other_durations,
        'duration_url' => url::site(implode($duration_url_path, '/')),
		'sig2bugs' => $signature_to_bugzilla,
		'start'        => $start,
		'top_crashers' => $top_crashers,
		'total_crashes' => $totalCrashes,
				       ));
	}
    }

    /**
     * Generates the report from a URI perspective.
     * URLs are truncated after the query string
     * 
     * @param   string product name 
     * @param   string version Example: 3.7a1pre
     * @return  null
     */
    public function byurl($product, $version=null) {
        if (empty($version)) {
            $this->_handleEmptyVersion($product, 'byurl');
        }
        
	$this->navigationChooseVersion($product, $version);
        $by_url_model = new TopcrashersByUrl_Model();
        list($start_date, $end_date, $top_crashers) = 
	  $by_url_model->getTopCrashersByUrl($product, $version);

        cachecontrol::set(array(
            'expires' => time() + (60 * 60)
        ));

        $this->setViewData(array(
	    'beginning' => $start_date,
            'ending_on' => $end_date,
    	    'nav_selection' => 'top_url',
            'product'       => $product,
            'version'       => $version,
            'top_crashers'  => $top_crashers,
            'url_nav' => url::site('products/'.$product),
        ));
    }

    /**
     * Generates the report from a domain name perspective
     * 
     * @param string product name 
     * @param string version Example: 3.7a1pre
     */
    public function bydomain($product, $version=null) {
        if (empty($version)) {
            $this->_handleEmptyVersion($product, 'bydomain');
        }

	$this->navigationChooseVersion($product, $version);
        $by_url_model = new TopcrashersByUrl_Model();
        list($start_date, $end_date, $top_crashers) = 
	  $by_url_model->getTopCrashersByDomain($product, $version);

        cachecontrol::set(array(
            'expires' => time() + (60 * 60)
        ));

        $this->setViewData(array(
	    'beginning' => $start_date,
            'ending_on' => $end_date,
    	    'nav_selection' => 'top_domain',            
            'product'       => $product,
            'version'       => $version,
            'top_crashers'  => $top_crashers,
            'url_nav' => url::site('products/'.$product),
        ));
    }

    /**
     * List the top 100 (x) Alexa top site domains, ordered by site ranking, and 
 	 * show the bugs that affect them.
     * 
	 * @access 	public
     * @param 	string 	The product name (e.g. 'Firefox')
     * @param 	string 	The version (e.g. '3.7a1pre')
 	 * @return 	void
     */
    public function bytopsite($product, $version=null) {
        if (empty($version)) {
            $this->_handleEmptyVersion($product, 'bytopsite');
        }

		$by_url_model = new TopcrashersByUrl_Model();
        list($start_date, $end_date, $top_crashers) = $by_url_model->getTopCrashersByTopsiteRank($product, $version);

        cachecontrol::set(array(
            'expires' => time() + (60 * 60)
        ));

        $this->setViewData(array(
	    	'beginning' 	=> $start_date,
            'ending_on' 	=> $end_date,
    	    'nav_selection' => 'top_topsite',            
            'product'       => $product,
            'version'       => $version,
            'top_crashers'  => $top_crashers,
            'url_nav' => url::site('products/'.$product),
        ));
    }

    /**
     * AJAX GET method which returns last 2 weeks of 
     * Aggregated crash signatures based on
     * signaturesforurl/{product}/{version}?url={url_encoded_url}&page={page}
     * product - Firefox
     * version - 3.0.3
     * url - http://www.youtube.com/watch
     * page - page offset, defaults to 1
     */
    public function signaturesforurl($product, $version){
      $url = urldecode( $_GET['url']);
      $page = 1;
      if( array_key_exists('page', $_GET)){
        $page = intval($_GET['page']);
      }

      header('Content-Type: text/javascript');
      $this->auto_render = false;
      $by_url_model =  new TopcrashersByUrl_Model();

        cachecontrol::set(array(
            'expires' => time() + (60 * 60)
        ));
      
      echo json_encode($by_url_model->getSignaturesByUrl($product, $version, $url, $page));
    }

    /**
     * AJAX GET method which returns all urls under this domain
     * which have had crash reports in the last 2 weeks.
     * urlsfordomain/{product}/{version}?domain={url_encoded_domain}&page={page}
     * product - Firefox
     * version - 3.0.3
     * domain - www.youtube.com
     * page - page offset, defaults to 1
     */
    public function urlsfordomain($product, $version){
      $domain = urldecode( $_GET['domain']);
      $page = 1;
      if( array_key_exists('page', $_GET)){
        $page = intval($_GET['page']);
      }
      header('Content-Type: text/javascript');
      $this->auto_render = false;
      $by_url_model =  new TopcrashersByUrl_Model();

      cachecontrol::set(array(
          'expires' => time() + (60 * 60)
      ));
      
      echo json_encode($by_url_model->getUrlsByDomain($product, $version, $domain, $page));
    }
}
