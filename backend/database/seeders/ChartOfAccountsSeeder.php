<?php

namespace Database\Seeders;

use App\Models\Accounting\Account;
use App\Models\Accounting\AccountingSetting;
use App\Models\Accounting\TaxRate;
use Illuminate\Database\Seeder;

/**
 * Default chart of accounts for a South African estate agency (VAT vendor). Idempotent — only fills in what's
 * missing, so it is safe on a live system. Admins can add, rename and archive accounts afterwards; accounts with a
 * system_key are used by automatic postings and can be renamed but not archived.
 */
class ChartOfAccountsSeeder extends Seeder
{
    // code, name, type, subtype, system_key
    public const ACCOUNTS = [
        ['1000', 'Business Current Account', 'asset', 'bank', 'bank_main'],
        ['1010', 'Savings / Call Account', 'asset', 'bank', null],
        ['1050', 'Petty Cash', 'asset', 'bank', null],
        ['1100', 'Accounts Receivable (Debtors)', 'asset', 'receivable', 'ar'],
        ['1200', 'Prepayments & Deposits Paid', 'asset', 'current_asset', null],
        ['1300', 'VAT Input', 'asset', 'vat_input', 'vat_input'],
        ['1500', 'Office Equipment', 'asset', 'fixed_asset', null],
        ['1510', 'Computer Equipment', 'asset', 'fixed_asset', null],
        ['1520', 'Furniture & Fittings', 'asset', 'fixed_asset', null],
        ['1530', 'Motor Vehicles', 'asset', 'fixed_asset', null],
        ['1590', 'Accumulated Depreciation', 'asset', 'fixed_asset', null],

        ['2000', 'Accounts Payable (Creditors)', 'liability', 'payable', 'ap'],
        ['2100', 'Agent Commissions Payable', 'liability', 'current_liability', null],
        ['2200', 'VAT Output', 'liability', 'vat_output', 'vat_output'],
        ['2210', 'VAT Payable to SARS', 'liability', 'current_liability', 'vat_payable'],
        ['2300', 'PAYE Payable', 'liability', 'current_liability', 'paye_payable'],
        ['2310', 'UIF Payable', 'liability', 'current_liability', 'uif_payable'],
        ['2320', 'SDL Payable', 'liability', 'current_liability', 'sdl_payable'],
        ['2330', 'Net Salaries Payable', 'liability', 'current_liability', 'salaries_payable'],
        ['2400', 'Accrued Expenses', 'liability', 'current_liability', null],
        ['2500', 'Credit Card', 'liability', 'current_liability', null],
        ['2700', 'Long-term Loan', 'liability', 'long_term_liability', null],
        ['2800', "Directors' Loan Accounts", 'liability', 'long_term_liability', null],

        ['3000', 'Share Capital', 'equity', 'equity', null],
        ['3100', 'Retained Earnings', 'equity', 'retained_earnings', 'retained_earnings'],
        ['3200', 'Dividends Declared', 'equity', 'equity', null],
        ['3900', 'Opening Balance Equity', 'equity', 'equity', 'opening_balance'],

        ['4000', 'Sales Commission Income', 'income', 'revenue', 'commission_income'],
        ['4010', 'Rental Management Fees (PayProp)', 'income', 'revenue', null],
        ['4020', 'Letting Fees', 'income', 'revenue', null],
        ['4030', 'Bond Origination Fees (Mackenzie Bonds)', 'income', 'revenue', null],
        ['4100', 'Referral Fees Received', 'income', 'revenue', null],
        ['4200', 'Interest Received', 'income', 'other_income', null],
        ['4900', 'Sundry Income', 'income', 'other_income', null],

        ['5000', 'Agent Commission Payouts', 'expense', 'cost_of_sales', 'agent_commission'],
        ['5010', 'Referral Fees Paid', 'expense', 'cost_of_sales', null],
        ['6000', 'Advertising & Property Portals', 'expense', 'operating_expense', null],
        ['6010', 'Bank Charges', 'expense', 'operating_expense', 'bank_charges'],
        ['6020', 'Salaries & Wages', 'expense', 'operating_expense', 'salaries'],
        ['6025', 'Employer UIF & SDL', 'expense', 'operating_expense', 'employer_contributions'],
        ['6030', 'Rent', 'expense', 'operating_expense', null],
        ['6040', 'Telephone & Internet', 'expense', 'operating_expense', null],
        ['6050', 'Electricity & Water', 'expense', 'operating_expense', null],
        ['6060', 'Fuel & Travel', 'expense', 'operating_expense', null],
        ['6070', 'Insurance', 'expense', 'operating_expense', null],
        ['6080', 'PPRA, FFC & Professional Body Fees', 'expense', 'operating_expense', null],
        ['6090', 'Accounting, Audit & Legal Fees', 'expense', 'operating_expense', null],
        ['6100', 'Office Supplies & Stationery', 'expense', 'operating_expense', null],
        ['6110', 'Software & Subscriptions', 'expense', 'operating_expense', null],
        ['6120', 'Repairs & Maintenance', 'expense', 'operating_expense', null],
        ['6130', 'Training & CPD', 'expense', 'operating_expense', null],
        ['6140', 'Depreciation', 'expense', 'operating_expense', null],
        ['6150', 'Entertainment', 'expense', 'operating_expense', null],
        ['6900', 'Sundry Expenses', 'expense', 'operating_expense', null],
        ['7000', 'Interest Paid', 'expense', 'other_expense', null],
        ['8000', 'Income Tax', 'expense', 'other_expense', null],
    ];

    public function run(): void
    {
        foreach ([['Standard rate (15%)', 'STD', 15, true], ['Zero-rated (0%)', 'ZERO', 0, false], ['Exempt', 'EXEMPT', 0, false], ['No VAT', 'NONE', 0, false]] as [$name, $code, $rate, $default]) {
            TaxRate::firstOrCreate(['code' => $code], ['name' => $name, 'rate' => $rate, 'is_default' => $default]);
        }

        foreach (self::ACCOUNTS as [$code, $name, $type, $subtype, $key]) {
            $existing = $key ? Account::where('system_key', $key)->first() : null;
            if ($existing || Account::where('code', $code)->exists()) {
                continue;
            }
            Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'subtype' => $subtype, 'system_key' => $key]);
        }

        $settings = AccountingSetting::current();
        if (! $settings->default_bank_account_id) {
            $settings->update(['default_bank_account_id' => Account::where('system_key', 'bank_main')->value('id')]);
        }
    }
}
