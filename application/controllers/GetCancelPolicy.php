<?php

namespace controllers;

/**
 * Description of GetCancelPolicy
 *
 * @author Kawin Glomjai <g.kawin@live.com>
 */
use core\Controller;
use \Exception;

class GetCancelPolicy extends Controller
{

    var $url;
    var $opts = array();
    var $suppliercode;
    var $temp_log = array();

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->suppliercode = GetSupplierCode();
        $this->url = config_item(_DOTW_STAGE);
        save_log_data();
    }

    public function index()
    {
        $post = XMLPost2Array($this->input->post());
        if (isset($post['LoginName']))
        {
            $post['Username'] = $post['LoginName'];
            unset($post['LoginName']);
        }

        if (isset($post['DocID']))
        {
            $post['HBooking'] = $post['DocID'];
            unset($post['DocID']);
        }

        $this->load->model('getbook_model');
        $this->load->model('policy_model');

        $bookidlist = array_map('base64_decode', explode('#|#', $post['HBooking']));
        $Policies = array();
        $post['bookItinerary'] = array();
        foreach ($bookidlist as $idx => $bookid)
        {
            //get booking split room type;
            $bookItinerary = isJson($bookid, true);
            foreach ($bookItinerary as $itin)
            {
                $post['bookItinerary'][$itin['runno']] = $itin['bC'];
            }
            unset($bookItinerary);

            try
            {
                $this->retrieveBooking($post);
                $this->temp_log['request']['retrieveBooking'][$idx] = $post['request'];
                $this->temp_log['response']['retrieveBooking'][$idx] = $post['response'];

                $Policies = array_merge($Policies, $this->policyItem($post));
            } catch (Exception $ex)
            {
                log_message('info', 'Exception : ' . $ex->getMessage() . '; LINE : ' . $ex->getLine() . '; FILE : ' . $ex->getFile());
            }
        }

        unset($bookidlist);

        $o_hotel = @GetHotelCode($post['HotelId'], $this->suppliercode);
        $arrMakePolicyXML = array(
            "ResNo" => $post['ResNo'],
            "HBooking" => $post['HBooking'],
            "HotelId" => $post['HotelId'],
            "HotelName" => $o_hotel->SpHotelName,
            "arrPolicy" => $Policies
        );


        $this->load->view('policy_response', array('getpolicy' => $arrMakePolicyXML));
        xmllog21s('GetCancelPolicy', $this->temp_log);
    }

    /**
     * 
     * @param array $post
     */
    private function retrieveBooking(array &$post)
    {
        $this->getbook_model->getRequest($post);
        $post['response'] = $this->httpcurl->open($this->url)
                ->setRequestHeader('Content-Type', 'text/xml')
                ->send($post['request']);
    }

    /**
     * 
     * @param array $post
     * @return type
     */
    private function policyItem(array &$post)
    {

        foreach ($post['response']['content'] as $response)
        {
            $o_xml = parse_xml($response);
            $post['CancelPolicyID_decode'][0] = array(
                'typecode' => (string) current($o_xml->xpath('//roomTypeCode')),
                'bkf' => (string) current($o_xml->xpath('//rateBasis'))
            );
            
            if(!isset($post['HotelId']))
            {
                $post['HotelId'] = GetHotelCodeBySpHotelCode((string) current($o_xml->xpath('.//serviceId')), $this->suppliercode)->WSCode;
            }

            $query = current($this->policy_model->getQueriesRoom($post));
            $post['query_policy'] = $this->policy_model->getQueryPolicy();
            $this->policy_model->groupPolicies($post, $o_xml->xpath($query));
        }
        return $this->policy_model->getPolicies();
    }

}
