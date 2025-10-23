<?php
namespace App\Http\Controllers\Api\Leads;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;


use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Models\LeadContact;
use App\Models\Product;
use App\Models\SaleStage;
use App\Models\LeadProduct;

class LeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Lead::query()
            ->with(['destination:id,flag,name,iso_3166_2', 'accountManager:id,name'])
            ->withCount(['contacts', 'comments as notes_count']);

        if ($search = $request->get('q')) {
            $q->where(function ($s) use ($search) {
                $s->where('lead_name', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%");
            });
        }

        $sortBy    = $request->get('sort_by', 'lead_name');
        $direction = strtolower($request->get('direction', 'asc')) === 'desc' ? 'desc' : 'asc';
        $sortable  = ['lead_name', 'city', 'created_at', 'contacts_count', 'notes_count'];
        if (!in_array($sortBy, $sortable, true)) $sortBy = 'lead_name';

        $perPage = (int) $request->get('per_page', 10);

        $leads = $q->orderBy($sortBy, $direction)
            ->paginate($perPage)
            ->appends($request->query());

        return response()->json($leads);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lead_id'        => ['nullable', 'integer', 'exists:leads,id'],
            'lead_name'      => ['required', 'string', 'max:255'],
            'destination_id' => ['required', 'integer'],
            'city'           => ['nullable', 'string', 'max:255'],
        ]);

        if (!empty($data['lead_id'])) {
            $lead = Lead::findOrFail($data['lead_id']);
            $lead->update([
                'lead_name'      => $data['lead_name'],
                'destination_id' => $data['destination_id'],
                'city'           => $data['city'] ?? null,
            ]);
            return response()->json(['message' => 'Lead updated successfully', 'lead' => $lead], 200);
        }

        $lead = Lead::create([
            'lead_name'      => $data['lead_name'],
            'destination_id' => $data['destination_id'],
            'city'           => $data['city'] ?? null,
        ]);

        return response()->json(['message' => 'Lead created successfully', 'lead' => $lead], 201);
    }

    public function show(Lead $lead): JsonResponse
    {
        $lead->load([
            'destination:id,name,flag,iso_3166_2',
            'contacts',
            'comments' => fn($q) => $q->latest('created_at'),
            'comments.user:id,name',
            'accountManager:id,name',
        ]);

        return response()->json($lead);
    }

    public function destroy(Lead $lead): JsonResponse
    {
        $lead->leadProducts()->delete();
        $lead->contacts()->delete();
        $lead->comments()->delete();
        $lead->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function assignAccountManager(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'user_ids.user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $lead->update(['account_manager_id' => $validated['user_ids']['user_id'] ?? null]);

        return response()->json([
            'message' => 'Account Manager updated successfully',
            'lead'    => $lead->fresh(['accountManager']),
        ], 200);
    }

    public function bulkImporter(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'leads'                           => ['required', 'array'],
            'leads.*.Name'                    => ['required', 'string', 'max:255'],
            'leads.*.City'                    => ['nullable', 'string', 'max:255'],
            'leads.*.Destination'             => ['nullable'],
            'leads.*.First Name'              => ['nullable', 'string', 'max:255'],
            'leads.*.Last Name'               => ['nullable', 'string', 'max:255'],
            'leads.*.Email'                   => ['nullable', 'string', 'email', 'max:255'],
            'leads.*.Job Title'               => ['nullable', 'string', 'max:255'],
            'leads.*.Phone'                   => ['nullable', 'string', 'max:50'],

            // Each product column contains a Stage name (string). If empty -> we record as "skipped".
            'leads.*.SAMS Manage'             => ['nullable', 'string', 'max:255'],
            'leads.*.SAMS Pay'                => ['nullable', 'string', 'max:255'],
            'leads.*.SAMS Pay Client Management' => ['nullable', 'string', 'max:255'],
            'leads.*.SAMS Perform'            => ['nullable', 'string', 'max:255'],
        ]);

        $productColumns = [
            'SAMS Manage'                 => 'SAMS Manage',
            'SAMS Pay'                    => 'SAMS Pay',
            'SAMS Pay Client Management'  => 'SAMS Pay Client Management',
            'SAMS Perform'                => 'SAMS Perform',
        ];

        $created    = 0;
        $updated    = 0;
        $contactsUp = 0;
        $lpUpserts  = 0;

        // Collect diagnostics of not-inserted / skipped items
        $skips = [
            'leads_skipped_for_blank_name' => [],         // [ {row_index, reason} ]
            'contacts_skipped_no_identifier' => [],       // [ {row_index, lead_id, reason} ]
            'lead_products_skipped_no_stage' => [],       // [ {row_index, lead_id, product} ]
            'lead_products_conflicts' => [],              // [ {row_index, lead_id, product, reason} ]
            'leads_existing' => [],                       // [ {row_index, lead_id, lead_name} ]
            'row_errors' => [],                           // [ {row_index, reason, row_payload} ]
        ];

        DB::transaction(function () use (
            &$created, &$updated, &$contactsUp, &$lpUpserts,
            $validated, $productColumns, &$skips
        ) {
            foreach ($validated['leads'] as $idx => $row) {
                try {
                    $leadName = trim((string) \Illuminate\Support\Arr::get($row, 'Name', ''));
                    $city     = self::n(\Illuminate\Support\Arr::get($row, 'City'));
                    $destRaw  = \Illuminate\Support\Arr::get($row, 'Destination');

                    $destinationId = self::resolveDestinationId($destRaw) ?? 826;

                    if ($leadName === '') {
                        $skips['leads_skipped_for_blank_name'][] = [
                            'row_index' => $idx,
                            'reason'    => 'Blank Name after trimming.',
                        ];
                        continue;
                    }

                    // Lead upsert by name (case-insensitive)
                    $lead = \App\Models\Lead::whereRaw('LOWER(lead_name) = ?', [\Illuminate\Support\Str::lower($leadName)])->first();

                    if (!$lead) {
                        $lead = \App\Models\Lead::create([
                            'lead_name'      => $leadName,
                            'city'           => $city,
                            'destination_id' => $destinationId,
                        ]);
                        $created++;
                    } else {
                        // Record that it already existed (not a failure, just not "inserted")
                        $skips['leads_existing'][] = [
                            'row_index' => $idx,
                            'lead_id'   => $lead->id,
                            'lead_name' => $lead->lead_name,
                        ];

                        $dirty = false;
                        if ($city && $city !== $lead->city) {
                            $lead->city = $city;
                            $dirty = true;
                        }
                        if ($destinationId && $destinationId !== (int) $lead->destination_id) {
                            $lead->destination_id = $destinationId;
                            $dirty = true;
                        }
                        if ($dirty) {
                            $lead->save();
                            $updated++;
                        }
                    }

                    // Contact upsert
                    $first     = self::n(\Illuminate\Support\Arr::get($row, 'First Name'));
                    $last      = self::n(\Illuminate\Support\Arr::get($row, 'Last Name'));
                    $email     = self::n(\Illuminate\Support\Arr::get($row, 'Email'));
                    $jobTitle  = self::n(\Illuminate\Support\Arr::get($row, 'Job Title'));
                    $phone     = self::n(\Illuminate\Support\Arr::get($row, 'Phone'));
                    $fullName  = trim(implode(' ', array_filter([$first, $last])));

                    $contactQuery = \App\Models\LeadContact::query()->where('lead_id', $lead->id);
                    if ($email) {
                        $contactQuery->where('email', $email);
                    } elseif ($phone) {
                        $contactQuery->where('phone', $phone);
                    } else {
                        if (!$first && !$last && !$jobTitle && !$phone) {
                            $skips['contacts_skipped_no_identifier'][] = [
                                'row_index' => $idx,
                                'lead_id'   => $lead->id,
                                'reason'    => 'No email/phone and no other meaningful contact fields.',
                            ];
                            // continue into products — contact is optional
                            goto PRODUCTS;
                        }
                    }

                    $contact = $contactQuery->first();

                    if (!$contact) {
                        $contact = new \App\Models\LeadContact();
                        $contact->lead_id    = $lead->id;
                        $contact->first_name = $first;
                        $contact->last_name  = $last;
                        $contact->name       = $fullName ?: null;
                        $contact->email      = $email;
                        $contact->phone      = $phone;
                        $contact->job_title  = $jobTitle;
                        $contact->save();
                        $contactsUp++;
                    } else {
                        $dirty = false;
                        foreach ([
                            'first_name' => $first,
                            'last_name'  => $last,
                            'name'       => ($fullName ?: null),
                            'email'      => $email,
                            'phone'      => $phone,
                            'job_title'  => $jobTitle,
                        ] as $field => $val) {
                            if ($val && $val !== $contact->{$field}) {
                                $contact->{$field} = $val;
                                $dirty = true;
                            }
                        }
                        if ($dirty) {
                            $contact->save();
                            $contactsUp++;
                        }
                    }

                    // Products + Stages
                    PRODUCTS:
                    foreach ($productColumns as $column => $productName) {
                        try {
                            $stageName = self::n(\Illuminate\Support\Arr::get($row, $column));
                            if (!$stageName) {
                                // The product column exists for this template, but no stage value provided in this row
                                $skips['lead_products_skipped_no_stage'][] = [
                                    'row_index' => $idx,
                                    'lead_id'   => $lead->id,
                                    'product'   => $productName,
                                ];
                                continue;
                            }

                            // Ensure Product (by name)
                            $product = \App\Models\Product::whereRaw('LOWER(name) = ?', [\Illuminate\Support\Str::lower($productName)])->first();
                            if (!$product) {
                                $product = \App\Models\Product::create(['name' => $productName]);
                            }

                            // Ensure Stage (global, not product-scoped in your latest code)
                            $stage = \App\Models\SaleStage::whereRaw('LOWER(name) = ?', [\Illuminate\Support\Str::lower($stageName)])->first();
                            if (!$stage) {
                                $stage = \App\Models\SaleStage::create(['name' => $stageName]);
                            }

                            // Upsert lead_products with stage_id
                            $lp = \App\Models\LeadProduct::where('lead_id', $lead->id)
                                ->where('product_id', $product->id)
                                ->first();

                            if (!$lp) {
                                \App\Models\LeadProduct::create([
                                    'lead_id'    => $lead->id,
                                    'product_id' => $product->id,
                                    'stage_id'   => $stage->id,
                                ]);
                                $lpUpserts++;
                            } else {
                                if ($lp->stage_id !== $stage->id) {
                                    $lp->stage_id = $stage->id;
                                    $lp->save();
                                    $lpUpserts++;
                                }
                            }
                        } catch (\Throwable $e) {
                            $skips['lead_products_conflicts'][] = [
                                'row_index' => $idx,
                                'lead_id'   => $lead->id ?? null,
                                'product'   => $productName,
                                'reason'    => $e->getMessage(),
                            ];
                            // keep processing other products/rows
                        }
                    }
                } catch (\Throwable $e) {
                    $skips['row_errors'][] = [
                        'row_index'  => $idx,
                        'reason'     => $e->getMessage(),
                        'row_payload'=> $row, // echo back the offending row for quick debugging
                    ];
                    // continue with next row
                }
            }
        });

        return response()->json([
            'message'            => 'Bulk import completed',
            'summary' => [
                'leads_created'     => $created,
                'leads_updated'     => $updated,
                'contacts_upserted' => $contactsUp,
                'lead_products_set' => $lpUpserts,
            ],
            // Everything that wasn’t inserted / was skipped with reasons:
            'not_inserted' => $skips,
        ], 201);
    }

    /** helpers */
    private static function n($v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private static function resolveDestinationId($raw): ?int
    {
        if ($raw === null) return null;
        if (is_numeric($raw)) return (int) $raw;
        return null;
    }

}
