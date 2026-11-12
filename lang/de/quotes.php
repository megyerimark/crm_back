<?php

return [
    'date_format' => 'j. F Y',
    'errors' => [
        'not_editable' => 'Nur Angebote im Entwurf können bearbeitet werden. Dupliziere ein versendetes Angebot.',
        'not_sendable' => 'Dieses Angebot ist bereits abgeschlossen und kann nicht erneut gesendet werden.',
        'no_items' => 'Füge dem Angebot mindestens eine Position hinzu.',
        'validity_passed' => 'Das Angebot ist nicht mehr gültig.',
        'already_answered' => 'Auf dieses Angebot wurde bereits geantwortet.',
        'no_recipient' => 'Gib eine E-Mail-Adresse für den Versand an.',
    ],
    'activity' => [
        'sent' => 'Angebot gesendet: :number – :title',
        'accepted' => 'Angebot angenommen: :number (:name)',
        'declined' => 'Angebot abgelehnt: :number',
    ],
    'follow_up' => [
        'task_title' => 'Angebot nachfassen: :number – :title',
    ],
    'mail' => [
        'greeting' => 'Sehr geehrte Kundin, sehr geehrter Kunde,',
        'sent_subject' => 'Angebot: :title – :company',
        'sent_line' => ':company hat Ihr Angebot (:number) erstellt. Über die Schaltfläche unten können Sie es online ansehen und mit einem Klick annehmen.',
        'sent_total' => 'Gesamtbetrag: :total',
        'sent_valid' => 'Das Angebot ist gültig bis :date.',
        'sent_action' => 'Angebot ansehen',
        'salutation' => "Mit freundlichen Grüßen\n:name\n:company",
        'open_quote' => 'Angebot öffnen',
        'viewed_subject' => ':customer hat dein Angebot geöffnet (:number)',
        'viewed_line' => ':customer hat das Angebot „:title“ gerade geöffnet. Jetzt ist ein guter Zeitpunkt für einen Anruf.',
        'accepted_subject' => 'Angebot angenommen: :number',
        'accepted_line' => ':customer hat das Angebot „:title“ angenommen. Glückwunsch!',
        'declined_subject' => 'Angebot abgelehnt: :number',
        'declined_line' => ':customer hat das Angebot „:title“ abgelehnt.',
        'declined_reason' => 'Begründung: :reason',
        'follow_up_subject' => 'Zeit zum Nachfassen: :number – :title',
        'follow_up_line' => 'Du hast das Angebot „:title“ vor :days Tagen an :customer gesendet und noch keine Antwort erhalten.',
        'follow_up_viewed' => 'Der Kunde hat es bereits geöffnet (:count Mal) und steht vermutlich kurz vor einer Entscheidung.',
        'follow_up_not_viewed' => 'Der Kunde hat es noch nicht geöffnet – vielleicht ist die E-Mail untergegangen.',
        'follow_up_tip' => 'Wir haben außerdem eine Aufgabe angelegt, damit du es nicht vergisst.',
    ],
];
