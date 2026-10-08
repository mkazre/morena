<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\Invoice;
use App\Models\Deal;
use App\Models\EmailLog;
use App\Models\Lead;
use App\Models\Setting;
use App\Services\Mailer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Message templates (WhatsApp + email), sending email from the CRM, and emailing invoices. */
class CommsController extends Controller
{
    public const RELATED = ['lead' => Lead::class, 'deal' => Deal::class, 'invoice' => Invoice::class];

    public function templates(Request $request)
    {
        $user = $request->user();

        return [
            'whatsapp' => config('morena.templates.whatsapp'),
            'email' => collect(config('morena.templates.email'))->except(['invoice', 'invoice_reminder']),
            'defaults' => ['agent' => $user->name, 'agent_phone' => $user->phone, 'company' => Setting::current()->company_name],
        ];
    }

    public function send(Request $request, Mailer $mailer)
    {
        $data = $request->validate([
            'to' => ['required', 'email'], 'subject' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:10000'],
            'related_type' => ['nullable', Rule::in(array_keys(self::RELATED))], 'related_id' => ['nullable', 'integer'],
        ]);
        $related = $data['related_type'] ?? null ? self::RELATED[$data['related_type']]::find($data['related_id']) : null;
        if ($related instanceof Lead) {
            abort_unless($request->user()->hasAnyRole(['admin', 'branch_manager']) || $related->assigned_agent_id === $request->user()->staff?->id, 404);
            $related->update(['last_contacted_at' => now(), 'status' => $related->status === 'new' ? 'contacted' : $related->status]);
        }
        $log = $mailer->send($data['to'], $data['subject'], $data['body'], $related, $request->user());
        abort_if($log->status === 'failed', 502, 'The email could not be sent: '.$log->error);

        return ['id' => $log->id, 'status' => $log->status];
    }

    public function history(Request $request)
    {
        $type = self::RELATED[$request->input('related_type')] ?? abort(422, 'related_type required');

        return ['data' => EmailLog::where('related_type', $type)->where('related_id', $request->integer('related_id'))->latest('created_at')->limit(30)->get()
            ->map(fn ($e) => ['subject' => $e->subject, 'to' => $e->to, 'status' => $e->status, 'at' => $e->created_at->toIso8601String()])];
    }

    /** POST /finance/invoices/{id}/email — plain-text tax invoice with banking details. */
    public function emailInvoice(Request $request, int $id, Mailer $mailer)
    {
        $invoice = Invoice::with(['contact', 'lines'])->findOrFail($id);
        abort_if(in_array($invoice->status, ['draft', 'void'], true), 422, 'Approve the invoice before sending it.');
        $to = $request->validate(['to' => ['nullable', 'email']])['to'] ?? $invoice->contact->email;
        abort_unless($to, 422, 'This customer has no email address — add one under Customers & suppliers.');
        $log = $mailer->send($to, ...self::invoiceEmail($invoice, 'invoice'), related: $invoice, by: $request->user());
        abort_if($log->status === 'failed', 502, 'The email could not be sent: '.$log->error);

        return ['status' => 'sent', 'to' => $to];
    }

    /** [subject, body] for the invoice or overdue-reminder template. */
    public static function invoiceEmail(Invoice $invoice, string $template): array
    {
        $tpl = config('morena.templates.email.'.$template);
        $lines = $invoice->lines->map(fn ($l) => '• '.$l->description.'  R '.number_format($l->line_total, 2))->implode("\n")
            ."\nSubtotal R ".number_format($invoice->subtotal, 2)."\nVAT R ".number_format($invoice->vat_total, 2)."\nTotal R ".number_format($invoice->total, 2);
        $values = ['client' => $invoice->contact->name, 'number' => $invoice->number, 'amount' => number_format($invoice->balanceDue(), 2),
            'due' => $invoice->due_date->format('j M Y'), 'lines' => $lines, 'footer' => AccountingSetting::current()->invoice_footer,
            'company' => Setting::current()->company_name];

        return [Mailer::fill($tpl['subject'], $values), Mailer::fill($tpl['body'], $values)];
    }
}
