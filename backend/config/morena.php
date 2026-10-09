<?php

/*
| Morena CRM configuration that isn't per-organisation data.
| Message templates use {placeholders}: {client} {agent} {agent_phone} {property} {link} {company} {amount} {due}.
*/
return [
    // Shown in the footer of every page. Bump on each release (major.minor.patch).
    'version' => '1.5.0',

    'templates' => [
        'whatsapp' => [
            'lead_intro' => ['label' => 'Introduce yourself', 'body' => 'Hi {client}, this is {agent} from {company}. Thanks for your interest — when would suit you for a quick call about what you\'re looking for?'],
            'lead_followup' => ['label' => 'Follow up', 'body' => 'Hi {client}, {agent} from {company} here. Just checking in — are you still looking? I have a few new options that may suit you.'],
            'viewing_confirm' => ['label' => 'Confirm a viewing', 'body' => 'Hi {client}, confirming our viewing of {property}. Please reply YES to confirm. — {agent}, {company}'],
            'upload_link' => ['label' => 'Send document upload link', 'body' => 'Hi {client}, please upload your documents securely here: {link} — {agent}, {company}'],
            'offer_update' => ['label' => 'Offer update', 'body' => 'Hi {client}, good news on {property}! Please give me a call when you can. — {agent}'],
        ],
        'email' => [
            'lead_intro' => ['label' => 'Introduction', 'subject' => 'Your property search with {company}',
                'body' => "Dear {client},\n\nThank you for your enquiry. I'd love to understand exactly what you're looking for so I can send you the best options.\n\nWhen would suit you for a short call?\n\nKind regards,\n{agent}\n{agent_phone}\n{company}"],
            'mandate_renewal' => ['label' => 'Mandate renewal', 'subject' => 'Renewing the mandate for {property}',
                'body' => "Dear {client},\n\nThe marketing mandate for {property} is coming up for renewal. I'd like to discuss the activity so far and our plan for the next period.\n\nKind regards,\n{agent}\n{company}"],
            'fica_request' => ['label' => 'FICA documents request', 'subject' => 'Documents needed — {property}',
                'body' => "Dear {client},\n\nTo proceed we need your FICA documents (ID, proof of address, proof of funds). You can upload them securely here:\n{link}\n\nKind regards,\n{agent}\n{company}"],
            'invoice' => ['label' => 'Invoice', 'subject' => 'Tax invoice {number} from {company}',
                'body' => "Dear {client},\n\nPlease find details of invoice {number} below.\n\nAmount due: R {amount}\nDue date: {due}\n\n{lines}\n\n{footer}\n\nKind regards,\n{company}"],
            'invoice_reminder' => ['label' => 'Overdue reminder', 'subject' => 'Reminder: invoice {number} is overdue',
                'body' => "Dear {client},\n\nOur records show invoice {number} for R {amount} was due on {due} and is still outstanding. If you've already paid, please ignore this note or send us the proof of payment.\n\n{footer}\n\nKind regards,\n{company}"],
        ],
    ],

    // Transfer duty (Transfer Duty Act) for natural persons — editable here when SARS updates it each Budget.
    'transfer_duty' => [
        'effective' => '2025-04-01',
        'note' => 'SARS transfer duty rates effective 1 April 2025. Verify on sars.gov.za before quoting a client.',
        'brackets' => [
            ['from' => 0, 'rate' => 0, 'base' => 0],
            ['from' => 1210000, 'rate' => 3, 'base' => 0],
            ['from' => 1663800, 'rate' => 6, 'base' => 13614],
            ['from' => 2329300, 'rate' => 8, 'base' => 53544],
            ['from' => 2994800, 'rate' => 11, 'base' => 106784],
            ['from' => 13310000, 'rate' => 13, 'base' => 1241456],
        ],
    ],
    'prime_rate' => 10.5, // indicative SA prime % for the bond calculator default; agents can change it per quote
];
