<?php

namespace controllers;

use core\Controller;
use \Exception;

class ViewCancelPolicy extends Controller
{

    var $url;
    var $opts = array();
    var $suppliercode;
    var $templog = array();

    /**
     * 
     */
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->suppliercode = GetSupplierCode();
        $this->url = config_item(_DOTW_STAGE);
        save_log_data();
    }

    /**
     * 
     */
    public function index()
    {
        $post = XMLPost2Array($this->input->post());
        $post['Debug'] = $this->input->post('Debug');
        //if request from B2B.
        if (!isset($post['HotelId']) AND $post['HotelCode'] != '')
        {
            $post['HotelId'] = $post['HotelCode'];
        }

        $this->load->model('search_model');
        $this->load->model('policy_model');
        try
        {
            //search products.
            $this->searchHotels($post);
            $this->temp_log['request']['search'] = $post['request'];
            $this->temp_log['response']['search'] = $post['response'];

            //view cancel policy.
            $policies = $this->getPolicies($post);
        } catch (Exception $ex)
        {
            log_message('info', 'Exception : ' . $ex->getMessage() . '; LINE : ' . $ex->getLine() . '; FILE : ' . $ex->getFile());
            $post['response']['errors'] = $ex->getMessage();
            $policies = array();
        }


        $o_hotel = GetHotelCode($post['HotelId'], $this->suppliercode);
        $data['arrViewCancelPolicy'] = array(
            "HotelId" => $post['HotelId'],
            "HotelName" => $o_hotel->SpHotelName,
            "Policies" => $policies
        );
        unset($o_hotel);
        $this->load->view('policy_response', $data);
        xmllog21s('ViewCancelPolicy', $post);
    }

    /**
     * 
     * @param array $post
     */
    private function searchHotels(array &$post)
    {
        $this->search_model->fetchHotelList($post);
        $this->search_model->searchHotelsRequest($post);
        $this->policy_model->decodePolicyId($post);
        $a_group = $this->policy_model->getRegroupPolicy($post);
        $filters = $this->policy_model->getModifyFilter($a_group);
        $this->search_model->setModifyFilter($post, $filters);


        //Send xml request to supplier.
        $post['response'] = $this->httpcurl
                ->open($this->url)
                ->setRequestHeader('Content-Type', 'text/xml')
                ->send($post['request']);

        if (filter_input(INPUT_POST, 'Debug') == 1)
        {
            print_r($post);
        }
    }

    /**
     * 
     * @param array $post
     * @return type
     */
    private function getPolicies(array &$post)
    {
        foreach ($post['response']['content'] as $response)
        {
            $o_xml = parse_xml($response);
            if ((string) $o_xml->successful != 'TRUE')
            {
                log_message('warning', 'some query unable process.');
                continue;
            }

            $queries = $this->policy_model->getQueriesRoom($post);
            $post['query_policy'] = $this->policy_model->getQueryPolicy();
            foreach ($queries as $query)
            {
                $this->policy_model->groupPolicies($post, $o_xml->xpath($query));
            }
        }
        return ($this->policy_model->getPolicies());
    }

}
