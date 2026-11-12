<?php

return [
    'date_format' => 'F j, Y',
    'errors' => [
        'not_editable' => 'Only draft quotes can be edited. Duplicate a sent quote instead.',
        'not_sendable' => 'This quote is already closed and cannot be sent again.',
        'no_items' => 'Add at least one line item to the quote.',
        'validity_passed' => 'The quote is no longer valid.',
        'already_answered' => 'This quote has already been answered.',
        'no_recipient' => 'Enter an e-mail address to send the quote to.',
    ],
    'activity' => [
        'sent' => 'Quote sent: :number – :title',
        'accepted' => 'Quote accepted: :number (:name)',
        'declined' => 'Quote declined: :number',
    ],
    'follow_up' => [
        'task_title' => 'Follow up quote: :number – :title',
    ],
    'mail' => [
        'greeting' => 'Dear Customer,',
        'sent_subject' => 'Quote: :title – :company',
        'sent_line' => ':company has prepared your quote (:number). Use the button below to view it online and accept it with one click.',
        'sent_total' => 'Total: :total',
        'sent_valid' => 'The quote is valid until :date.',
        'sent_action' => 'View quote',
        'salutation' => "Kind regards,\n:name\n:company",
        'open_quote' => 'Open quote',
        'viewed_subject' => ':customer opened your quote (:number)',
        'viewed_line' => ':customer has just opened the quote ":title". Now is a great time to call while it is fresh.',
        'accepted_subject' => 'Quote accepted: :number',
        'accepted_line' => ':customer accepted the quote ":title". Congratulations!',
        'declined_subject' => 'Quote declined: :number',
        'declined_line' => ':customer declined the quote ":title".',
        'declined_reason' => 'Reason: :reason',
        'follow_up_subject' => 'Time to follow up: :number – :title',
        'follow_up_line' => 'You sent the quote ":title" to :customer :days days ago and have not heard back yet.',
        'follow_up_viewed' => 'The customer has opened it (:count times) and is probably about to decide.',
        'follow_up_not_viewed' => 'The customer has not opened it yet – the e-mail may have slipped through.',
        'follow_up_tip' => 'We also created a task so you will not forget.',
    ],
];
