<?php

namespace AbuseIO\Http\Controllers;

use Request;
use AbuseIO\Models\Ticket;
use AbuseIO\Models\Note;
use Input;
use AbuseIO\Models\Brand;
use AbuseIO\Models\Account;

/**
 * Controller handling the ASH interface to contacts
 *
 * Class AshController
 * @package AbuseIO\Http\Controllers
 */
class AshController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @param integer $ticketID
     * @param string $token
     * @return \Illuminate\Http\Response
     */
    public function index($ticketID, $token)
    {
        $ticket = Ticket::find($ticketID);
        $AshAuthorisedBy = Request::get('AshAuthorisedBy');

        if ($AshAuthorisedBy == 'TokenIP') {
            $account = Account::find($ticket->accountIp);
        }
        if ($AshAuthorisedBy == 'TokenDomain') {
            $account = Account::find($ticket->accountDomain);
        }

        $brand = empty($account) ? Brand::getDefault() : $account->brand;

        if (empty($brand)) {
            abort(500);
        }

        return view('ash')
            ->with('brand', $brand)
            ->with('ticket', $ticket)
            ->with('token', $token);

    }

    /**
     * Method to add a note to a ticket
     *
     * @param integer $ticketID
     * @param string $token
     * @return \Illuminate\Http\Response
     */
    public function addNote($ticketID, $token)
    {
        $submittor = false;

        $ticket = Ticket::find($ticketID);
        $AshAuthorisedBy = Request::get('AshAuthorisedBy');

        if ($AshAuthorisedBy == 'TokenIP') {
            $account = Account::find($ticket->accountIp);
            $submittor = trans('ash.basic.ip') . ' ' . trans('ash.communication.contact');
        }
        if ($AshAuthorisedBy == 'TokenDomain') {
            $account = Account::find($ticket->accountDomain);
            $submittor = trans('ash.basic.domain') . ' ' . trans('ash.communication.contact');
        }

        $brand = empty($account) ? Brand::getDefault() : $account->brand;

        if (empty($brand) || empty($submittor)) {
            abort(500);
        }

        $text = Input::get('text');
        if (empty($text)) {
            $message = 'You cannot add an empty message!';
        } else {
            $message = 'Note has been added.';

            $note = new Note();
            $note->ticket_id = $ticket->id;
            $note->submitter = $submittor;
            $note->text = $text;
            $note->save();
        }

        return view('ash')
            ->with('brand', $brand)
            ->with('ticket', $ticket)
            ->with('token', $token)
            ->with('message', $message);
    }
}
