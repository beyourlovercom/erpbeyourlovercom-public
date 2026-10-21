<?php

namespace App\Modules\Support\Experience\Controllers;

use App\Modules\Customers\Infrastructure\Models\Customer;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Support\Application\CustomerSupportProjection;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Support\Infrastructure\Models\SupportTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CustomerSupportTicketController
{
    public const STATUSES = ['open', 'waiting_customer', 'waiting_internal', 'resolved', 'closed'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public function __construct(private readonly SupportTicketService $tickets, private readonly CustomerSupportProjection $projection) {}

    public function create(Customer $customer): Response
    {
        return Inertia::render('Customers/Support/Create', $this->formProps($customer, null));
    }

    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate($this->rules());
        $ticket = $this->tickets->create(['customer_id' => $customer->id, ...$data], $request->user());
        return redirect()->route('customers.support-tickets.show', [$customer, $ticket])->with('success', '支持工单已创建');
    }

    public function show(Customer $customer, SupportTicket $ticket): Response
    {
        $this->assertCustomerTicket($customer, $ticket);
        $tickets = $this->projection->forCustomer($customer->id);
        return Inertia::render('Customers/Support/Show', [
            'customer' => ['id' => $customer->id, 'name' => $customer->name, 'customer_number' => $customer->customer_number],
            'ticket' => collect($tickets)->firstWhere('id', $ticket->id),
            'canOperate' => request()->user()?->hasPermission('customers.operate') ?? false,
        ]);
    }

    public function edit(Customer $customer, SupportTicket $ticket): Response
    {
        $this->assertCustomerTicket($customer, $ticket);
        return Inertia::render('Customers/Support/Edit', $this->formProps($customer, $ticket->load('assignee')));
    }

    public function update(Request $request, Customer $customer, SupportTicket $ticket): RedirectResponse
    {
        $this->assertCustomerTicket($customer, $ticket);
        $data = $request->validate($this->rules(true));
        $this->tickets->update($ticket, $data, $request->user());
        return redirect()->route('customers.support-tickets.show', [$customer, $ticket])->with('success', '支持工单已更新');
    }

    private function assertCustomerTicket(Customer $customer, SupportTicket $ticket): void
    {
        abort_unless((int) $ticket->customer_id === (int) $customer->id, 404);
    }

    private function formProps(Customer $customer, ?SupportTicket $ticket): array
    {
        return [
            'customer' => ['id' => $customer->id, 'name' => $customer->name, 'customer_number' => $customer->customer_number],
            'ticket' => $ticket,
            'statuses' => self::STATUSES,
            'priorities' => self::PRIORITIES,
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function rules(bool $update = false): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['sometimes', Rule::in(self::PRIORITIES)],
            'assigned_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('is_active', true))],
            ...($update ? ['status' => ['required', Rule::in(self::STATUSES)]] : []),
        ];
    }
}
