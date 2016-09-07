<?php

namespace controllers;

use core\Controller;
use \Exception;

/**
 * Description of SearchHotels
 *
 * @author Kawin Glomjai <g.kawin@live.com>
 */
class SearchHotels extends Controller
{

    var $url;
    var $suppliercode;

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
        $post['Debug'] = $this->input->post('Debug');
        $sessionid = '';

        $this->load->model('search_model');
        try
        {
            $this->search_model->fetchHotelList($post);
            $this->search_model->searchHotelsRequest($post);

            if ((isset($post['ServiceCode']) && $post['ServiceCode'] != '') AND ( isset($post['ABFType']) && $post['ABFType'] != ''))
            {
                $this->modifyFilter($post);
            }

            //Send xml request to supplier.
            $this->benchmark->mark('search_exec_time_start');
            $post['response'] = $this->httpcurl
                    ->open($this->url)
                    ->setRequestHeader('Content-Type', 'text/xml')
                    ->send($post['request']);
            $this->benchmark->mark('search_exec_time_stop');

            //post search products.
            $sessionid = $this->search_model->response($post);
        } catch (Exception $ex)
        {
            log_message('info', 'Exception : ' . $ex->getMessage() . '; LINE : ' . $ex->getLine() . '; FILE : ' . $ex->getFile());
            $post['response']['errors'][] = $ex->getMessage();
        }

        @$this->load->view('search_response', array('sessionId' => $sessionid, 'post' => $post));
        collect_statis_search($post);
    }

    /**
     * 
     * @param array $post
     */
    private function modifyFilter(array &$post)
    {
        $filter = array();
        foreach (preg_grep('/(ServiceCode|ABFType)/', array_keys($post)) as $idx => $keyItem)
        {
            $filter[] = $this->search_model->getFiltersItem($post, $keyItem);
        }
        $this->search_model->setModifyFilter($post, $filter);
    }

}
