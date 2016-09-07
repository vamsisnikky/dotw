<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace controllers;

/**
 * Description of BookHotelV2
 *
 * @author Kawin Glomjai <g.kawin@live.com>
 */
use core\Controller;
use Exception;

class BookHotelV2 extends Controller
{

    private $temp_log = array();
    private $requests = array();
    private $responses = array();
    public $suppliercode = 0;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->suppliercode = GetSupplierCode();
        $this->url = config_item(_DOTW_STAGE);
        save_log_data();
    }

    public function __destruct()
    {
        $this->responses = array();
        $this->requests = array();
    }

    public function index()
    {
        $err = array();
        $a_post = convertXMLPOSTBooking($this->input->post());
        $concat_method = '';
        try
        {
            $this->search($a_post);
            $this->getRooms($a_post);
            $this->prebook($a_post);
            $arrCompleteServices = $this->postbook($a_post);
        } catch (Exception $ex)
        {
            log_message('NOTICE', 'Exception : ' . $ex->getMessage() . '; LINE : ' . $ex->getLine() . '; FILE : ' . $ex->getFile());
            $err['response']['errors'][] = $ex->getMessage();

            //auto cancel booked item when system error report
            if (isset($this->postbook_model) AND count($this->postbook_model->getCacheBookOnRequest()) > 0)
            {
                try
                {
                    $this->autocancel($a_post);
                } catch (Exception $ex)
                {
                    log_message('NOTICE', 'Exception : ' . $ex->getMessage() . '; LINE : ' . $ex->getLine() . '; FILE : ' . $ex->getFile());
                    $err['response']['errors'] = $ex->getMessage();
                }
                $concat_method = ' + Autocancel';
            }
        }

        //final part
        $this->load->view('book_response', array('post' => $a_post, 'arrCompleteServices' => $arrCompleteServices, 'err' => $err));
        xmllog21s('BookingHotel' . $concat_method, collectLog());
    }

    /**
     * 
     * @param array $a_post
     * @throws Exception
     */
    private function search(array $a_post)
    {
        if (empty($a_post))
        {
            throw new Exception('Check post input data.');
        }

        //load
        $this->load->model('search_model');
        $requests = array();
        foreach ($a_post as $post)
        {
            $this->search_model->fetchHotelList($post);
            $this->search_model->searchHotelsRequest($post);
            $filter = array();
            foreach (preg_grep('/(RoomCatgWScode|BFType)/', array_keys($post)) as $keyItem)
            {
                //overriding key contents
                if ($keyItem == 'RoomCatgWScode')
                {
                    $post['ServiceCode'] = $post[$keyItem];
                    $keyItem = 'ServiceCode';
                }

                if ($keyItem == 'BFType')
                {
                    $post['ABFType'] = $post[$keyItem];
                    $keyItem = 'ABFType';
                }
                $filter[] = $this->search_model->getFiltersItem($post, $keyItem);
            }

            $this->search_model->setModifyFilter($post, $filter);
            $requests = array_merge($requests, $post['request']);
        }

        //Send xml request to supplier.
        $this->responses = $this->httpcurl
                ->open($this->url)
                ->setRequestHeader('Content-Type', 'text/xml')
                ->send($requests);

        $this->requests = $requests;

        collectLog(__FUNCTION__, $this->requests, $this->responses);
    }

    /**
     * 
     * @param array $a_post
     * @throws Exception
     */
    private function getRooms(array $a_post)
    {
        if (empty($this->responses) || (count($this->responses['content']) != count($a_post)))
        {
            throw new Exception('check search product request / response');
        }

        //load model.
        $this->load->model('getrooms_model');
        $requests = array();
        foreach ($a_post as $idx => $post)
        {
            //get matched product for verify rate.
            $post['roomType'] = getRoomType($post);
            $post['response']['content'][0] = $this->responses['content'][$idx];
            $post['queries'] = $this->getrooms_model->getQueriesRoom($post, $this->getrooms_model->getQueryConditionPath($post));
            $groupRooms = $this->getrooms_model->groupRoom($post);
            $requests[] = $this->getrooms_model->request($post, $this->requests[$idx], $groupRooms);
        }
        
        //Send xml request to supplier.
        $this->responses = $this->httpcurl
                ->open($this->url)
                ->setRequestHeader('Content-Type', 'text/xml')
                ->send($requests);

        $this->requests = $requests;

        collectLog(__FUNCTION__, $this->requests, $this->responses);
    }

    /**
     * 
     * @param array $a_post
     */
    private function prebook(array $a_post)
    {
        //load model 
        $this->load->model('book/prebook_model');
        $requests = array();
        foreach ($a_post as $idx => $post)
        {
            $post['response']['content'][0] = $this->responses['content'][$idx];
            $post['roomType'] = getRoomType($post);
            $this->prebook_model->getRequest($post);
            $requests = array_merge($requests, $post['request']);
        }
        
        //Send xml request to supplier.
        $this->responses = $this->httpcurl
                ->open($this->url)
                ->setRequestHeader('Content-Type', 'text/xml')
                ->send($requests);

        $this->requests = $requests;

        collectLog(__FUNCTION__, $this->requests, $this->responses);
    }

    /**
     * 
     * @param array $a_post
     * @return type
     * @throws Exception
     */
    private function postbook(array $a_post)
    {
        //load
        $this->load->model('book/postbook_model');
        $_return = array();
        foreach ($a_post as $idx => $post)
        {
            $post['response']['content'][0] = $this->responses['content'][$idx];
            $this->postbook_model->setBookStatusId(2);
            if ($this->postbook_model->getBookingItinerary($post) === false)
            {
                continue;
            }
            $_return[] = $this->postbook_model->getResponse($post);
        }

        if (count($this->postbook_model->getCacheBookOnRequest()) > 0)
        {
            throw new Exception('Booking incompleted.(booking status not confirm).');
        }
        return $_return;
    }

    /**
     * when system detected error booking or emergency case. cancell all transection.(rollback)
     * @param array $a_post
     */
    private function autocancel(array $a_post)
    {
        $this->load->model('cancel_model');
        $bookOnReq = $this->postbook_model->getCacheBookOnRequest();
        $_request = array();
        $_response = array();
        foreach ($a_post as $idx => $post)
        {
            $post['bookItinerary'][0] = $bookOnReq[$idx];
            $this->cancel_model->getRequest($post, 'no');


            //Send xml request to supplier.
            $post['response'] = $this->httpcurl
                    ->open($this->url)
                    ->setRequestHeader('Content-Type', 'text/xml')
                    ->send($post['request']);

            collectLog(__FUNCTION_ . "(State 1 : getcancelcharge)", $post['request'], $post['response']);

            //confirm cancell booking
            $this->cancel_model->getRequest($post, 'yes');
            //Send xml request to supplier.
            $post['response'] = $this->httpcurl
                    ->open($this->url)
                    ->setRequestHeader('Content-Type', 'text/xml')
                    ->send($post['request']);

            $_request[] = $post['request'][0];
            $_response[] = $post['response'];
            $this->cancel_model->checkConfirmCancelled($post);

            collectLog(__FUNCTION_ . "(State 1 : confirmcancel)", $post['request'], $post['response']);
        }
    }

}
