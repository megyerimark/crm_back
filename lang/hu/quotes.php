<?php

return [
    'date_format' => 'Y. F j.',
    'errors' => [
        'not_editable' => 'Csak piszkozat állapotú ajánlat szerkeszthető. Elküldött ajánlatnál készíts másolatot.',
        'not_sendable' => 'Ez az ajánlat már lezárult, nem küldhető ki újra.',
        'no_items' => 'Adj hozzá legalább egy tételt az ajánlathoz.',
        'validity_passed' => 'Az ajánlat érvényessége lejárt.',
        'already_answered' => 'Erre az ajánlatra már érkezett válasz.',
        'no_recipient' => 'Adj meg egy e-mail-címet, ahová az ajánlatot küldjük.',
    ],
    'activity' => [
        'sent' => 'Ajánlat elküldve: :number – :title',
        'accepted' => 'Ajánlat elfogadva: :number (:name)',
        'declined' => 'Ajánlat elutasítva: :number',
    ],
    'follow_up' => [
        'task_title' => 'Ajánlat utánkövetése: :number – :title',
    ],
    'mail' => [
        'greeting' => 'Kedves Ügyfelünk!',
        'sent_subject' => 'Árajánlat: :title – :company',
        'sent_line' => 'A(z) :company elkészítette az árajánlatát (:number). Az alábbi gombbal online megtekintheti és egy kattintással el is fogadhatja.',
        'sent_total' => 'Végösszeg: :total',
        'sent_valid' => 'Az ajánlat érvényes: :date-ig.',
        'sent_action' => 'Ajánlat megtekintése',
        'salutation' => "Üdvözlettel,\n:name\n:company",
        'open_quote' => 'Ajánlat megnyitása',
        'viewed_subject' => ':customer megnyitotta az ajánlatot (:number)',
        'viewed_line' => ':customer épp most nyitotta meg a(z) „:title” ajánlatot. Most érdemes felhívni, amíg friss.',
        'accepted_subject' => 'Elfogadták az ajánlatot: :number',
        'accepted_line' => ':customer elfogadta a(z) „:title” ajánlatot. Gratulálunk!',
        'declined_subject' => 'Elutasították az ajánlatot: :number',
        'declined_line' => ':customer elutasította a(z) „:title” ajánlatot.',
        'declined_reason' => 'Indoklás: :reason',
        'follow_up_subject' => 'Ideje utánkövetni: :number – :title',
        'follow_up_line' => ':days napja küldted el a(z) „:title” ajánlatot :customer részére, de még nem érkezett válasz.',
        'follow_up_viewed' => 'Az ügyfél már megnyitotta (:count alkalommal) – jó eséllyel döntés előtt áll.',
        'follow_up_not_viewed' => 'Az ügyfél még nem nyitotta meg – lehet, hogy a levél elkerülte a figyelmét.',
        'follow_up_tip' => 'Létrehoztunk egy teendőt is, hogy ne felejtsd el.',
    ],
];
