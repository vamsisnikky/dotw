<?php

namespace controllers;

/**
 * Description of CancelRSVN
 *
 * @author Kawin Glomjai <g.kawin@live.com>
 */
use core\Controller;
use \Exception;

class CancelRSVN extends Controller
{

    var $temp_log = array();
    var $url = '';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->url = config_item(_DOTW_STAGE);
        save_log_data();
    }

    public function index()
    {
        $post = XMLPost2Array($this->input->post());
        //array key for cancel reservation.
        if (isset($post['DocID']))
        {
            $post['HBooking'] = $post['DocID'];
            unset($post['DocID']);
        }

        $this->load->model('cancel_model');

        $bookidlist = array_map('base64_decode', explode('#|#', $post['HBooking']));
        foreach ($bookidlist as $idx => $bookid)
        {
            $bookItinerary = isJson($bookid, true);
            foreach ($bookItinerary as $itin)
            {
                $post['bookItinerary'][$itin['runno']] = $itin['bC'];
            }
            unset($bookItinerary);

            try
            {
                //notice for cancel panelty.
                $this->getPanetlyCharge($post);
                $this->temp_log['request']['getPanetlyCharge'][$idx] = $post['request'];
                $this->temp_log['response']['getPanetlyCharge'][$idx] = $post['response'];

                //cancel vooking processes.
                $this->getConfirmCancel($post);
                $this->temp_log['request']['getConfirmCancel'][$idx] = $post['request'];
                $this->temp_log['response']['getConfirmCancel'][$idx] = $post['response'];
            } catch (Exception $ex)
            {
                log_message('INFO', 'Exception : ' . $ex->getMessage() . '; LINE : ' . $ex->getLine() . '; FILE : ' . $ex->getFile());
                $post['response']['error'][] = $ex->getMessage();
            }
        }

        //final result
        $is_result = 'true';
        $errmsg = '';
        if (isset($post['response']['error']))
        {
            $is_result = 'false';
            $errmsg = implode('; ', $post['response']['error']);
        }

        $this->load->view('cancel_response', array(
            "resno" => $post['ResNo'],
            "hbid" => $post['HBooking'],
            "errmsg" => $errmsg,
            "is_result" => $is_result
        ));

        xmllog21s('CancelRSVN', $this->temp_log);
    }

    private function getPanetlyCharge(&$post)
    {
        $this->cancel_model->getRequest($post, 'no');

        //Send xml request to supplier.
        $post['response'] = $this->httpcurl
                ->open($this->url)
                ->setRequestHeader('Content-Type', 'text/xml')
                ->send($post['request']);
    }

    private function getConfirmCancel(&$post)
    {
        $this->cancel_model->getRequest($post, 'yes');
        //Send xml request to supplier.
        $post['response'] = $this->httpcurl
                ->open($this->url)
                ->setRequestHeader('Content-Type', 'text/xml')
                ->send($post['request']);

        $this->cancel_model->checkConfirmCancelled($post);
    }

}
