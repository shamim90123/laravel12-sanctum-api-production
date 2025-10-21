<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadComment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;


class LeadController extends Controller
{
    public function index(Request $request)
    {
        $query = Lead::with('destination:id,flag,name,iso_3166_2');

        if ($search = $request->get('q')) {
            $query->where(function($q) use ($search) {
                $q->where('lead_name', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->get('sort_by', 'lead_name');
        $direction = $request->get('direction', 'asc');

        $leads = $query->orderBy($sortBy, $direction)->paginate(10);

        return response()->json($leads);
    }

    public function store(Request $request)
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

            return response()->json([
                'message' => 'Lead updated successfully',
                'lead'    => $lead,
            ], 200);
        }

        $lead = Lead::create([
            'lead_name'      => $data['lead_name'],
            'destination_id' => $data['destination_id'],
            'city'           => $data['city'] ?? null,
        ]);

        return response()->json([
            'message' => 'Lead created successfully',
            'lead'    => $lead,
        ], 201);
    }

    public function show($id)
    {
        $lead = Lead::with([
                'destination:id,name,flag,iso_3166_2',
                'contacts',
                'comments' => function($q) { $q->latest('created_at'); },
                'comments.user:id,name' // include commenter name
            ])->find($id);

        if (!$lead) {
            return response()->json(['message' => 'Lead not found'], 404);
        }

        return response()->json($lead);
    }

    public function destroy(Lead $lead)
    {
        $lead->leadProducts()->delete();
        $lead->contacts()->delete();
        $lead->comments()->delete();

        // Finally delete the lead itself
        $lead->delete();
        return response()->json(['message' => 'Deleted']);
    }

    // ========================
    // Comment Endpoints
    // ========================

    // List comments for a lead (paginated)
    public function comments(Lead $lead, Request $request)
    {
        $perPage = (int) ($request->get('per_page', 10));
        $comments = LeadComment::with('user:id,name')
            ->where('lead_id', $lead->id)
            ->latest('created_at')
            ->paginate($perPage);

        return response()->json($comments);
    }

    // Create a comment for a lead
    public function storeComment(Request $request, Lead $lead)
    {
        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:5000'],
        ]);

        // If you use Sanctum, you likely want the authenticated user:
        $userId = auth()->id() ?? $request->get('user_id'); // fallback if you pass user_id explicitly

        if (!$userId) {
            return response()->json(['message' => 'User not identified'], 422);
        }

        $comment = LeadComment::create([
            'lead_id' => $lead->id,
            'user_id' => $userId,
            'comment' => $validated['comment'],
        ]);

        // eager load user name for response
        $comment->load('user:id,name');

        return response()->json([
            'message' => 'Comment added',
            'comment' => $comment,
        ], 201);
    }

    // Delete a specific comment
    public function destroyComment(Lead $lead, LeadComment $comment)
    {
        if ($comment->lead_id !== $lead->id) {
            return response()->json(['message' => 'Comment does not belong to this lead'], 422);
        }

        $comment->delete();

        return response()->json(['message' => 'Comment deleted']);
    }


    /**
     * GET: List products currently linked to the lead
     */
    public function products(Lead $lead)
    {
        // Return minimal fields needed by your UI (id, name, code/sku, etc.)
        $items = $lead->products()
            ->select('products.id', 'products.name')
            ->orderBy('products.name')
            ->get();

        return response()->json([
            'data' => $items,
        ]);
    }

     /**
     * PUT/POST: Assign products to a lead (replaces existing set).
     * Body: { product_ids: [1,2,3] }
     */
    public function assignProducts(Request $request, Lead $lead)
    {
        $validated = $request->validate([
            'product_ids'   => ['required', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);

        $ids = array_values(array_unique($validated['product_ids']));

        DB::transaction(function () use ($lead, $ids) {
            // Replace the whole set; use syncWithoutDetaching($ids) if you want additive behavior
            $lead->products()->sync($ids);
        });

        // Return updated list for convenience
        $items = $lead->products()
            ->select('products.id', 'products.name',)
            ->orderBy('products.name')
            ->get();

        return response()->json([
            'message' => 'Products assigned successfully.',
            'data'    => $items,
        ], Response::HTTP_OK);
    }


    /**
     * Show a list of all of the countries.
     *
     *
     */
    public function getCountries()
    {
        $countries = DB::table('countries')->get();

        return response()->json([
            'message' => 'Countries retrieved successfully.',
            'data'    => $countries,
        ], Response::HTTP_OK);
    }


    public function bulkImporter(Request $request)
    {
        // Accept any array of rows; we’ll map keys inside
        $data = $request->validate([
            'leads' => ['required', 'array'],
            'leads.*' => ['array'],
        ]);

        $created = [];

        $toBool = function ($v) {
            $s = strtolower(trim((string)$v));
            return in_array($s, ['1','true','yes','y','✓','✔','booked','done'], true);
        };

        $clean = function ($v) {
            $t = is_string($v) ? trim($v) : $v;
            return ($t === '' ? null : $t);
        };

        $findProductId = function (string $label) {
            // match by exact name (case-insensitive). Adjust if you have slugs/codes.
            return optional(
                Product::query()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($label)])
                    ->first(['id'])
            )->id;
        };

        // Map UI/product column labels to catalog names you actually store:
        $productColumns = [
            'SAMS Manage'               => 'SAMS Manage',
            'SAMS Pay'                  => 'SAMS Pay',
            'SAMS Pay Client Management'=> 'SAMS Pay Client Management',
            'SAMS Perform'              => 'SAMS Perform',
        ];
        // --------------------------------------------------------------------

        DB::transaction(function () use ($data, &$created, $clean, $toBool, $findProductId, $productColumns, $fallbackDestinationId) {

            foreach ($data['leads'] as $row) {
                // 1) Lead
                $leadName = $clean($row['Name'] ?? null);
                if (!$leadName) {
                    // skip blank rows safely; or throw if you want strict mode
                    continue;
                }

                $city = $clean($row['City'] ?? null);

                $lead = Lead::create([
                    'lead_name'      => $leadName,
                    'destination_id' => null, // <- required by schema; no mapping requested
                    'city'           => $city,
                ]);

                // 2) Primary Contact (optional)
                $firstName  = $clean($row['First Name'] ?? null);
                $lastName   = $clean($row['Last Name'] ?? null);
                $email      = $clean($row['Email'] ?? null);
                $phone      = $clean($row['Phone'] ?? null);
                $jobTitle   = $clean($row['Job Title'] ?? null);
                $itemId     = $clean($row['Item ID (auto generated)'] ?? null);
                $bookedDemo = $toBool($row['Booked Demo'] ?? '');

                $contact = null;
                // create a contact if there’s at least some contact info
                if ($firstName || $lastName || $email || $phone || $jobTitle || $itemId) {
                    $contact = LeadContact::create([
                        'lead_id'     => $lead->id,
                        'first_name'  => $firstName,
                        'last_name'   => $lastName,
                        'email'       => $email,
                        'phone'       => $phone,
                        'job_title'   => $jobTitle,
                        'item_id'     => $itemId,     // <-- ensure these columns exist
                        'booked_demo' => $bookedDemo, // <-- ensure boolean column exists
                        'is_primary'  => true,
                    ]);
                }

                // 3) Comment (optional)
                $commentText = $clean($row['Comments'] ?? null);
                if ($commentText) {
                    LeadComment::create([
                        'lead_id'    => $lead->id,
                        'contact_id' => optional($contact)->id, // can be null if your schema allows
                        'comment'    => $commentText,
                    ]);
                }

                // 4) Products (optional)
                foreach ($productColumns as $incomingCol => $catalogName) {
                    $val = $row[$incomingCol] ?? null;
                    if ($clean($val) === null) {
                        continue; // only link when not empty
                    }
                    $pid = $findProductId($catalogName);
                    if ($pid) {
                        // Use your relation or pivot model
                        // Example with a standard many-to-many:
                        if (method_exists($lead, 'products')) {
                            $lead->products()->syncWithoutDetaching([$pid]);
                        } else {
                            // or insert directly to `lead_products` pivot:
                            DB::table('lead_products')->updateOrInsert(
                                ['lead_id' => $lead->id, 'product_id' => $pid],
                                ['lead_id' => $lead->id, 'product_id' => $pid]
                            );
                        }
                    }
                }

                $created[] = $lead;
            }
        });

        return response()->json([
            'message' => 'Leads imported successfully',
            'leads'   => $created,
        ], 201);
    }
}
