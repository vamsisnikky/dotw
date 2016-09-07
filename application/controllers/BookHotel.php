<?php

namespace controllers;

/**
 * Description of BookHotel
 *
 * @author Kawin Glomjai <g.kawin@live.com>
 */
use core\Controller;
use \Exception;

class BookHotel extends Controller
{

    var $suppliercode;
    var $temp_log = array();
    var $cache_bookingid_generated = array();
    var $url = '';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->suppliercode = GetSupplierCode();
        $this->url = config_item(_DOTW_STAGE);
        save_log_data();
    }

    /**
     * index controller for start their process.
     */
    public function index()
    {
        $a_post = convertXMLPOSTBooking($this->input->post());

        //load
        $this->load->model('search_model');
        $this->load->model('getrooms_model');
        $this->load->model('book/prebook_model');
        $this->load->model('book/postbook_model');
        $this->load->model('cancel_model');

        $arrCompleteServices = array();
        $err = array();
        foreach ($a_post as $idx => &$post)
        {
            try
            {
                //search available product.
                $this->searchProduct($post);
                $this->temp_log['request']['search'][$idx] = $post['request'];
                $this->temp_log['response']['search'][$idx] = $post['response'];

                $this->getRoomsProduct($post);
                $this->temp_log['request']['getRooms'][$idx] = $post['request'];
                $this->temp_log['response']['getRooms'][$idx] = $post['response'];

                //generate booking request and send booking.
                $this->prebooking($post);
                $this->temp_log['request']['confirmbooking'][$idx] = $post['request'];
                $this->temp_log['response']['confirmbooking'][$idx] = $post['response'];

                //post booking after get response.
                $arrCompleteServices[$idx] = $this->postbooking($post);
                $is_error = 'FALSE';
            } catch (Exception $ex)
            {
                $is_error = 'TRUE';
                log_message('NOTICE', 'Exception : ' . $ex->getMessage() . '; LINE : ' . $ex->getLine() . '; FILE : ' . $ex->getFile());
                $err['response']['errors'][$idx] = $ex->getMessage();

                //auto cancel booked item when system error report
                if (count($this->postbook_model->getCacheBookingId()) > 0)
                {
                    $this->autocancel($post);
                    $this->temp_log['request']['auto_cancel'][$idx] = $post['request'];
                    $this->temp_log['response']['auto_cancel'][$idx] = $post['response'];
                }
            }
        }

        //concat room category when :::
        //1. same hotel(HOTEL,XML) and same period(XML)
        if ($a_post[0]['isConcatHBID'] == 'TRUE' && $is_error == 'FALSE')
        {
            $id = '';
            $ref = '';
            foreach ($arrCompleteServices as $item)
            {
                $id .= $item['Id'] . '#|#';
                $ref .= $item['RefHBId'] . '#|#';
            }
            foreach ($arrCompleteServices as &$fill)
            {
                $fill['Id'] = substr($id, 0, -3);
                $fill['RefHBId'] = substr($ref, 0, -3);
            }
        }

        //final part
        @$this->load->view('book_response', array('post' => $a_post, 'arrCompleteServices' => $arrCompleteServices, 'err' => $err));
        xmllog21s('BookingHotel', $this->temp_log);
    }

    /**
     * Search product available.
     * @param array $post
     */
    private function searchProduct(array &$post)
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
        //Send xml request to supplier.
        $post['response'] = $this->httpcurl
                ->open($this->url)
                ->setRequestHeader('Content-Type', 'text/xml')
                ->send($post['request']);
    }

    /**
     * 
     * @param array $post
     */
    private function getRoomsProduct(array &$post)
    {
        //check searchHotel request xml exists.
        if (!isset($post['request']) OR empty($post['request']))
        {
            throw new Exception('Please call FetchHotelList first!!');
        }

        //get matched product for verify rate.
        $post['queries'] = $this->getrooms_model->getQueriesRoom($post, $this->getrooms_model->getQueryConditionPath($post));
        $this->getrooms_model->setGroupRoom($post);

        //check product exists.
        if (count($this->getrooms_model->getGroupRoom()) == 0)
        {
            throw new Exception('Room type for selected not found.');
        }

        //modify request
        foreach ($post['request'] as $request)
        {
            $this->getrooms_model->setRequest($post, $request);
        }

        $post['request'] = $this->getrooms_model->getRequest();
         $post['response'] = $this->httpcurl
                ->open($this->url)
                ->setRequestHeader('Content-Type', 'text/xml')
                ->send($post['request']);
    }

    /**
     * get request product from search and create request booking.
     * @param array $post
     */
    private function prebooking(array &$post)
    {
        $this->prebook_model->getRequest($post);
        $post['response'] = $this->httpcurl
                ->open($this->url)
                ->setRequestHeader('Content-Type', 'text/xml')
                ->send($post['request']);
    }

    /**
     * after booking process and translate to xml booking in gateway 21 format.
     * @param array $post
     * @return array
     */
    private function postbooking(array &$post)
    {
        $this->postbook_model->setBookStatusId(2);
        $this->postbook_model->getBookingItinerary($post);
        return $this->postbook_model->getResponse($post);
    }

    /**
     * when system detected error booking or emergency case. cancell all transection.(rollback)
     * @param array $post
     */
    private function autocancel(array &$post)
    {
        $this->cancel_model->getRequest($post, 'no');

        //Send xml request to supplier.
        $post['response'] = $this->httpcurl
                ->open($this->url)
                ->setRequestHeader('Content-Type', 'text/xml')
                ->send($post['request']);

        //confirm cancell booking
        $this->cancel_model->getRequest($post, 'yes');
        //Send xml request to supplier.
        $post['response'] = $this->httpcurl
                ->open($this->url)
                ->setRequestHeader('Content-Type', 'text/xml')
                ->send($post['request']);

        $this->cancel_model->checkConfirmCancelled($post);
    }

}
