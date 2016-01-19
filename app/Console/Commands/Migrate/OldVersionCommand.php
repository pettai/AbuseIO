<?php

namespace AbuseIO\Console\Commands\Migrate;

use Illuminate\Console\Command;
use PhpMimeMailParser\Parser as MimeParser;
use AbuseIO\Parsers\Factory as ParserFactory;
use AbuseIO\Jobs\EvidenceSave;
use AbuseIO\Jobs\IncidentsValidate;
use AbuseIO\Jobs\IncidentsProcess;
use AbuseIO\Jobs\FindContact;
use AbuseIO\Models\Evidence;
use AbuseIO\Models\Ticket;
use AbuseIO\Models\Event;
use AbuseIO\Models\Contact;
use AbuseIO\Models\Netblock;
use AbuseIO\Models\Note;
use AbuseIO\Models\Account;
use Illuminate\Filesystem\Filesystem;
use Validator;
use Carbon;
use Config;
use Lang;
use DB;

/**
 * Class OldVersionCommand
 * @package AbuseIO\Console\Commands\Migrate
 */
class OldVersionCommand extends Command
{

    /**
     * The console command name.
     * @var string
     */
    protected $signature = 'migrate:oldversion
                            {--p|prepare : Prepares the migration by building all required caches }
                            {--s|start : Start the migration using cached evidence }
    ';

    /**
     * The console command description.
     * @var string
     */
    protected $description = 'List of send out pending notifications';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return boolean
     */
    public function handle()
    {
        $account = Account::system();

        if (!empty($this->option('prepare'))) {
            $this->info('building required evidence cache files');

            $filesystem = new Filesystem;
            $path       = storage_path() . '/migratation/';
            umask(0007);

            if (!$filesystem->isDirectory($path)) {
                // If a datefolder does not exist, then create it or die trying
                if (!$filesystem->makeDirectory($path, 0770)) {
                    $this->error(
                        'Unable to create directory: ' . $path
                    );
                    $this->exception();
                }

                if (!is_dir($path)) {
                    $this->error(
                        'Path vanished after write: ' . $path
                    );
                    $this->exception();
                }
                chgrp($path, config('app.group'));
            }


            DB::setDefaultConnection('abuseio3');

            $evidences = DB::table('Evidence')
                ->get();

            $this->output->progressStart(count($evidences));
            foreach ($evidences as $evidence) {
                //echo $evidence->ID . PHP_EOL;
                $filename = $path . "evidence_id_{$evidence->ID}.data";

                if (is_file($filename)) {
                    continue;
                }

                $rawEmail = $evidence->Data;

                $parsedMail = new MimeParser();
                $parsedMail->setText($rawEmail);

                // Start with detecting valid ARF e-mail
                $attachments = $parsedMail->getAttachments();
                $arfMail = [];

                foreach ($attachments as $attachment) {
                    if ($attachment->contentType == 'message/feedback-report') {
                        $arfMail['report'] = $attachment->getContent();
                    }

                    if ($attachment->contentType == 'message/rfc822') {
                        $arfMail['evidence'] = utf8_encode($attachment->getContent());
                    }

                    if ($attachment->contentType == 'text/plain') {
                        $arfMail['message'] = $attachment->getContent();
                    }
                }

                if (empty($arfMail['message']) && isset($arfMail['report']) && isset($arfMail['evidence'])) {
                    $arfMail['message'] = $parsedMail->getMessageBody();
                }

                // If we do not have a complete e-mail, then we empty the perhaps partially filled arfMail
                // which is useless, hence reset to false
                if (!isset($arfMail['report']) || !isset($arfMail['evidence']) || !isset($arfMail['message'])) {
                    $arfMail = false;
                }

                // Asking ParserFactory for an object based on mappings, or die trying
                $parser = ParserFactory::create($parsedMail, $arfMail);

                if ($parser !== false) {
                    $parserResult = $parser->parse();
                } else {
                    // Before we go into an error, lets see if this evidence was even linked to any ticket at all
                    // If not we can ignore the error and just smile and wave
                    $evidenceLinks = DB::table('EvidenceLinks')
                        ->where('EvidenceID', '=', $evidence->ID)
                        ->get();

                    if (count($evidenceLinks) === 0) {
                        continue;
                    }

                    $this->error(
                        'No parser available to handle message '.$evidence->ID.' from : ' . $evidence->Sender .
                        ' with subject: ' . $evidence->Subject
                    );
                    continue;
                }

                if ($parserResult !== false && $parserResult['errorStatus'] === true) {
                    $this->error(
                        'Parser has ended with fatal errors ! : ' . $parserResult['errorMessage']
                    );

                    $this->exception();
                }

                if ($parserResult['warningCount'] !== 0) {
                    $this->error(
                        'Configuration has warnings set as critical and ' .
                        $parserResult['warningCount'] . ' warnings were detected.'
                    );

                    $this->exception();
                }

                // Write the evidence into the archive
                $evidenceWrite = new EvidenceSave;
                $evidenceData = $rawEmail;
                $evidenceFile = $evidenceWrite->save($evidenceData);

                // Save the file reference into the database
                $evidenceSave = new Evidence();
                $evidenceSave->filename = $evidenceFile;
                $evidenceSave->sender = $parsedMail->getHeader('from');
                $evidenceSave->subject = $parsedMail->getHeader('subject');

                $incidentsProcess = new IncidentsProcess($parserResult['data'], $evidenceSave);

                /*
                 * Because google finds it 'obvious' not to include the IP address relating to abuse
                 * the IP field might now be empty with reparsing if the domain/label does not resolve
                 * anymore. For these cases we need to lookup the ticket that was linked to the evidence
                 * match the domain and retrieve its IP.
                 */
                foreach ($parserResult['data'] as $index => $incident) {
                    if ($incident->source == 'Google Safe Browsing' &&
                        $incident->domain != false &&
                        $incident->ip == '127.0.0.1'
                    ) {
                        // Get the list of tickets related to this evidence
                        $evidenceLinks = DB::table('EvidenceLinks')
                            ->where('EvidenceID', '=', $evidence->ID)
                            ->get();

                        // For each ticket check if the domain name is matching the evidence we need to update
                        foreach ($evidenceLinks as $evidenceLink) {
                            $ticket = DB::table('Reports')
                                ->where('ID', '=', $evidenceLink->ReportID)
                                ->first();

                            if ($ticket->Domain == $incident->domain) {
                                $incident->ip = $ticket->IP;
                            }
                        }

                        // Update the original object by overwriting it
                        $parserResult['data'][$index] = $incident;
                    }
                }


                // Only continue if not empty, empty set is acceptable (exit OK)
                if (!$incidentsProcess->notEmpty()) {
                    continue;
                }

                // Validate the data set
                if (!$incidentsProcess->validate()) {
                    $this->error(
                        'Validation failed of object.'
                    );
                    $this->exception();
                }


                $incidents = [];
                foreach ($parserResult['data'] as $incident) {
                    $incidents[$incident->ip][] = $incident;
                }

                DB::setDefaultConnection('mysql');
                $evidenceSave->save();
                DB::setDefaultConnection('abuseio3');

                $output = [
                    'evidenceId'    => $evidence->ID,
                    'evidenceData'  => $evidence->Data,
                    'incidents'     => $incidents,
                    'newId'         => $evidenceSave->id,
                ];

                if ($filesystem->put($filename, json_encode($output)) === false) {
                    $this->error(
                        'Unable to write file: ' . $filename
                    );

                    return false;
                }

                $this->output->progressAdvance();
            }

            $this->output->progressFinish();
        }



        if (!empty($this->option('start'))) {
            $this->info('starting migration - phase 1 - contact data');

            DB::setDefaultConnection('abuseio3');

            $customers = DB::table('Customers')
                ->get();

            DB::setDefaultConnection('mysql');

            $this->output->progressStart(count($customers));
            foreach ($customers as $customer) {
                $newContact = new Contact();
                $newContact->reference      = $customer->Code;
                $newContact->name           = $customer->Name;
                $newContact->email          = $customer->Contact;
                $newContact->auto_notify    = $customer->AutoNotify;
                $newContact->enabled        = 1;
                $newContact->account_id     = $account->id;
                $newContact->created_at     = Carbon::parse($customer->LastModified);
                $newContact->updated_at     = Carbon::parse($customer->LastModified);

                $validation = Validator::make($newContact->toArray(), Contact::createRules());

                if ($validation->fails()) {
                    $message = implode(' ', $validation->messages()->all());
                    $this->error('fatal error while creating contacts :' . $message);
                    $this->exception();
                } else {
                    $newContact->save();
                }

                $this->output->progressAdvance();
                echo " Working on contact {$customer->Code}      ";
            }
            $this->output->progressFinish();




            $this->info('starting migration - phase 2 - netblock data');

            DB::setDefaultConnection('abuseio3');

            $netblocks = DB::table('Netblocks')
                ->get();

            DB::setDefaultConnection('mysql');

            $this->output->progressStart(count($netblocks));
            foreach ($netblocks as $netblock) {
                $contact = FindContact::byId($netblock->CustomerCode);

                if ($contact->reference != $netblock->CustomerCode) {
                    $this->error('Contact lookup failed, mismatched results');
                    $this->$this->exception();
                }

                $newNetblock = new Netblock();
                $newNetblock->first_ip      = long2ip($netblock->begin_in);
                $newNetblock->last_ip       = long2ip($netblock->end_in);
                $newNetblock->description   = 'Imported from previous AbuseIO version which did not include a description';
                $newNetblock->contact_id    = $contact->id;
                $newNetblock->enabled       = 1;
                $newNetblock->created_at    = Carbon::parse($netblock->LastModified);
                $newNetblock->updated_at    = Carbon::parse($netblock->LastModified);

                $validation = Validator::make($newNetblock->toArray(), Netblock::createRules($newNetblock));

                if ($validation->fails()) {
                    $message = implode(' ', $validation->messages()->all());
                    $this->error('fatal error while creating contacts :' . $message);
                    $this->exception();
                } else {
                    $newNetblock->save();
                }

                $this->output->progressAdvance();
                echo " Working on netblock ". long2ip($netblock->begin_in) . "       ";
            }
            $this->output->progressFinish();



            $this->info('starting migration - phase 4 - Notes');

            DB::setDefaultConnection('abuseio3');

            $notes = DB::table('Notes')
                ->get();

            DB::setDefaultConnection('mysql');

            $this->output->progressStart(count($notes));
            foreach ($notes as $note) {
                $newNote = new Note();

                $newNote->ticket_id     = $note->ReportID;
                $newNote->submitter     = $note->Submittor;
                $newNote->text          = $note->Text;
                $newNote->hidden        = true;
                $newNote->viewed        = true;
                $newNote->created_at    = Carbon::parse($note->LastModified);
                $newNote->updated_at    = Carbon::parse($note->LastModified);

                $validation = Validator::make($newNote->toArray(), Note::createRules());

                if ($validation->fails()) {
                    $message = implode(' ', $validation->messages()->all());
                    $this->error('fatal error while creating contacts :' . $message);
                    $this->exception();
                } else {
                    $newNote->save();
                }

                $this->output->progressAdvance();
                echo " Working on note {$note->ID}       ";
            }
            $this->output->progressFinish();



            $this->info('starting migration - phase 4 - ticket and evidence data');

            DB::setDefaultConnection('abuseio3');

            $tickets = DB::table('Reports')
                ->get();

            $migrateCount = 0;
            foreach ($tickets as $ticket) {
                $evidenceLinks = DB::table('EvidenceLinks')
                    ->where('ReportID', '=', $ticket->ID)
                    ->get();

                $migrateCount = $migrateCount + count($evidenceLinks);
            }

            DB::setDefaultConnection('mysql');

            $this->output->progressStart($migrateCount);

            foreach ($tickets as $ticket) {
                // Get the list of evidence ID's related to this ticket
                DB::setDefaultConnection('abuseio3');
                $evidenceLinks = DB::table('EvidenceLinks')
                    ->where('ReportID', '=', $ticket->ID)
                    ->get();

                DB::setDefaultConnection('mysql');

                // DO NOT REMOVE! Legacy versions (1.0 / 2.0) have imports without evidence.
                // These dont have any linked evidence and will require a manual building of evidence
                // for now we ignore them. This will not affect any 3.x installations
                if ($ticket->CustomerName == 'Imported from AbuseReporter' ||
                    !empty(json_decode($ticket->Information)->importnote)
                ) {
                    // Manually build the evidence
                    continue;
                }


                if (count($evidenceLinks) != (int)$ticket->ReportCount) {
                    // Count does not match, known 3.0 bug so we will do a little magic to fix that
                } else {
                    // Start with building a classification lookup table  and switch out name for ID
                    // But first fix the names:
                    $replaces = [
                        'Possible DDOS sending NTP Server' => 'Possible DDoS sending Server',
                    ];
                    $old = array_keys($replaces);
                    $new = array_values($replaces);
                    $ticket->Class = str_replace($old, $new, $ticket->Class);
                    foreach ((array)Lang::get('classifications') as $classID => $class) {
                        if ($class['name'] == $ticket->Class) {
                            $ticket->Class = $classID;
                        }
                    }

                    // Also build a types lookup table and switch out name for ID
                    foreach ((array)Lang::get('types.type') as $typeID => $type) {
                        // Consistancy fixes:
                        $ticket->Type = ucfirst(strtolower($ticket->Type));

                        if ($type['name'] == $ticket->Type) {
                            $ticket->Type = $typeID;
                        }
                    }

                    // Create the ticket
                    $newTicket = new Ticket();

                    $newTicket->id                          = $ticket->ID;
                    $newTicket->ip                          = $ticket->IP;
                    $newTicket->domain                      = empty($ticket->Domain) ? '' : $ticket->Domain;
                    $newTicket->class_id                    = $ticket->Class;
                    $newTicket->type_id                     = $ticket->Type;

                    $newTicket->ip_contact_account_id       = $account->id;
                    $newTicket->ip_contact_reference        = $ticket->CustomerCode;
                    $newTicket->ip_contact_name             = $ticket->CustomerName;
                    $newTicket->ip_contact_email            = $ticket->CustomerContact;
                    $newTicket->ip_contact_api_host         = '';
                    $newTicket->ip_contact_auto_notify      = $ticket->AutoNotify;
                    $newTicket->ip_contact_notified_count   = $ticket->NotifiedCount;

                    $domainContact = FindContact::undefined();
                    $newTicket->domain_contact_account_id   = $domainContact->account_id;
                    $newTicket->domain_contact_reference    = $domainContact->reference;
                    $newTicket->domain_contact_name         = $domainContact->name;
                    $newTicket->domain_contact_email        = $domainContact->email;
                    $newTicket->domain_contact_api_host     = $domainContact->api_host;
                    $newTicket->domain_contact_auto_notify  = $domainContact->auto_notify;
                    $newTicket->domain_contact_notified_count = 0;

                    $newTicket->last_notify_count           = $ticket->LastNotifyReportCount;
                    $newTicket->last_notify_timestamp       = $ticket->LastNotifyTimestamp;

                    $newTicket->created_at                  = Carbon::createFromTimestamp($ticket->FirstSeen);
                    $newTicket->updated_at                  = Carbon::parse($ticket->LastModified);

                    if ($ticket->Status == 'CLOSED') {
                        $newTicket->status_id               = 2;
                    } elseif ($ticket->Status == 'OPEN') {
                        $newTicket->status_id               = 1;
                    } else {
                        $this->error('Unknown ticket status');
                        $this->exception();
                    }

                    // Validate the model before saving
                    $validator = Validator::make(
                        json_decode(json_encode($newTicket), true),
                        Ticket::createRules()
                    );
                    if ($validator->fails()) {
                        $this->error(
                            'DevError: Internal validation failed when saving the Ticket object ' .
                            implode(' ', $validator->messages()->all())
                        );
                        var_dump($ticket);
                        $this->exception();
                    }

                    $newTicket->save();

                    // Create all the events
                    foreach ($evidenceLinks as $evidenceLink) {
                        echo ".";
                        $path       = storage_path() . '/migratation/';
                        $filename = $path . "evidence_id_{$evidenceLink->EvidenceID}.data";

                        if (!is_file($filename)) {
                            $this->error('missing cache file ');
                            $this->exception();
                        }

                        $evidence = json_decode(file_get_contents($filename));
                        $evidenceID = $evidence->evidenceId;
                        $incidents = $evidence->incidents;

                        // Yes we only grab nr 0 from the array, because that is what the old aggregator did
                        // which basicly ignored a few incidents because they werent considered unique (which
                        // they were with the domain name)
                        $ip = $newTicket->ip;
                        $incidentTmp = $incidents->$ip;
                        $incident = $incidentTmp[0];

                        $newEvent = new Event;
                        $newEvent->evidence_id  = $evidenceID;
                        $newEvent->information  = $incident->information;
                        $newEvent->source       = $incident->source;
                        $newEvent->ticket_id    = $newTicket->id;
                        $newEvent->timestamp    = $incident->timestamp;

                        // Validate the model before saving
                        $validator = Validator::make(
                            json_decode(json_encode($newEvent), true),
                            Event::createRules()
                        );
                        if ($validator->fails()) {
                            $this->error(
                                'DevError: Internal validation failed when saving the Event object ' .
                                implode(' ', $validator->messages()->all())
                            );
                            $this->exception();
                        }

                        $newEvent->save();

                        $this->output->progressAdvance();
                        echo " Working on events from ticket {$ticket->ID}";
                    }
                }
            }
            $this->output->progressFinish();
        }

        return true;
    }

    /**
     *
     */
    private function exception()
    {
        $this->error('fatal error happend, ending migration (empty DB, fix problem, try again)');
        die();
    }
}