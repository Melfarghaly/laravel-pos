<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\Supplier;
use App\Branch;
use App\Product;
use App\Term;
use App\ShippingServiceProvider;
use App\PurchaseOrder;
use App\ProductItems;
use App\Currency;
use App\Company;
use Illuminate\Support\Facades\Auth;

class PurchaseOrderController extends Controller {

    public function __construct() {
        $this->middleware('auth');
    }

    public function index() {

        $purchase_orders = PurchaseOrder::all();

        $po_amount_array = [];
        // calculate total for each po
        foreach ($purchase_orders as $po) {
            $amount = 0;

            $tmp_array = [];

            foreach ($po->productItems as $item) {
                $amount = $amount + ($item->item_count * $item->unit_cost);
            }

            $tmp_array = [
                'po_id' => $po->id,
                'po_amount' => $amount
            ];

            array_push($po_amount_array, $tmp_array);
        }

        return view('purchaseorder.polist', [
            'purchase_orders' => $purchase_orders,
            'po_amounts' => $po_amount_array
        ]);
    }

    /**
     * Save a purchase order as draft when initializing.
     * This way we can show the purchase order number in the view
     *
     * @return int Purchase order Id / false when error
     *
     */
    private function savePoDraft() {
        try {
            $po = new PurchaseOrder();
            $po->supplier_id = 1;
            $po->ship_to_branch_id = 1;
            $po->purchase_rep = Auth::user()->staff->id;
            $po->due_date = date('Y-m-d');
            $po->currency_id = 1;
            $po->is_draft = 1;
            $po->save();

            return $po->id;
        } catch (\Exception $ex) {
            return false;
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create() {
        $draft = PurchaseOrder::where('purchase_rep', Auth::user()->staff->id)
                ->where('is_draft', 1);

        $draft_count = $draft->count();

        if ($draft_count) {
            if ($draft_count > 1) {
                // delete all the drafts for the user
                PurchaseOrder::where('purchase_rep', Auth::user()->staff->id)
                        ->where('is_draft', 1)
                        ->delete();

                // initiate a draft po
                $draft_id = $this->savePoDraft();
            } else {
                $draft_id = $draft->first()->id;
            }
        } else {
            // initiate a draft po
            $draft_id = $this->savePoDraft();
        }


        $suppliers = Supplier::all();
        $branches = Branch::all();
        $products = Product::all();
        $terms = Term::all();
        $shipping_services = ShippingServiceProvider::all();
        $user = Auth::user();
        $currency_list = Currency::all();

        return view('purchaseorder.new-po', [
            'suppliers' => $suppliers,
            'branches' => $branches,
            'products' => $products,
            'terms' => $terms,
            'shipping_services' => $shipping_services,
            'user' => $user,
            'draft_id' => $draft_id,
            'currency_list' => $currency_list
        ]);
    }

    public function update(Request $request) {

        $validator = Validator::make($request->all(), [
            'supplier' => 'required',
            'branch' => 'required',
            'po_terms' => 'required'
        ], [
            'po_terms.required' => 'The terms field is required'
        ]);

        if ($validator->fails()) {
            return redirect('/purchase-orders/create')
                ->withErrors($validator)
                ->withInput();
        }

        $po_delivery_date_arr = explode('-', $request->po_delivery_date);
        $po_delivery_date = $po_delivery_date_arr[2] . '-' . $po_delivery_date_arr[1] . '-' . $po_delivery_date_arr[0];

        $po_due_date_arr = explode('-', $request->po_due_date);
        $po_due_date = $po_due_date_arr[2] . '-' . $po_due_date_arr[1] . '-' . $po_due_date_arr[0];

        $po_expiry_date_arr = explode('-', $request->po_expiry_date);
        $po_expiry_date = $po_expiry_date_arr[2] . '-' . $po_expiry_date_arr[1] . '-' . $po_expiry_date_arr[0];

        $po_tax_invoice_date_arr = explode('-', $request->po_tax_invoice_date);
        $po_tax_invoice_date = $po_tax_invoice_date_arr[2] . '-' . $po_tax_invoice_date_arr[1] . '-' . $po_tax_invoice_date_arr[0];

        $po_supplier_invoice_date_arr = explode('-', $request->po_supplier_invoice_date);
        $po_supplier_invoice_date = $po_supplier_invoice_date_arr[2] . '-' . $po_supplier_invoice_date_arr[1] . '-' . $po_supplier_invoice_date_arr[0];

        try {
            $purchase_order = PurchaseOrder::find($request->purchase_order_id);

            $purchase_order->supplier_id = $request->supplier;
            $purchase_order->supplier_contact = $request->supplier_contact;
            $purchase_order->ship_to_branch_id = $request->branch;
            $purchase_order->purchase_rep = Auth::user()->staff->id;
            $purchase_order->terms_id = $request->po_terms;
            $purchase_order->shipping_service_id = $request->po_ship_via;
            $purchase_order->delivery_date = $po_delivery_date;
            $purchase_order->due_date = $po_due_date;
            $purchase_order->expiry_date = $po_expiry_date;
            $purchase_order->currency_id = $request->currency;
            $purchase_order->tax_invoice_number = $request->po_tax_invoice_number;
            $purchase_order->tax_invoice_date = $po_tax_invoice_date;
            $purchase_order->supplier_invoice_number = $request->po_supplier_invoice_number;
            $purchase_order->supplier_invoice_date = $po_supplier_invoice_date;
            $purchase_order->shipment_tracking_number = $request->po_shipment_tarcking_no;
            $purchase_order->weight_kg = $request->po_weight;
            $purchase_order->reference = $request->po_reference;
            $purchase_order->location_id = Auth::user()->branch_id;
            $purchase_order->is_draft = 0;

            $purchase_order->save();

            $request->session()->flash('success', 'Purchases order saved!');
            return redirect()->route('purchase_order_list');
        } catch (Exception $ex) {
            echo $ex->getMessage();
            $request->session()->flash('fail', 'An error occured while saving purchase order. Please try again!');
            return back()->withInput();
        }

    }

    public function getSupplierDetailsById($id) {
        $supplier = Supplier::find($id);

        echo json_encode([
            'code' => $supplier->code,
            'company_name' => $supplier->company_name,
            'address' => $supplier->address,
            'city' => $supplier->city,
//            'country' => $supplier->country->country_name
        ]);
    }

    public function getBranchDetailsById($id) {
        $branch = Branch::find($id);

        echo json_encode([
            'code' => $branch->code,
            'desc' => $branch->description,
            'address' => $branch->address,
            'city' => $branch->city,
//            'country' => $supplier->country->country_name
        ]);
    }

    public function getProductDescription($id) {
        $product = Product::find($id);

        echo $product->description;
    }

    public function getTermDueDate($id) {
        echo date('d-m-Y', strtotime("+ $id days"));
    }

    public function savePoProduct(Request $request) {
        // validate
        $this->validate($request, [
            'product_field_code' => 'required',
            'product_field_ordered' => 'required',
            'product_field_eachcost' => 'required',
            ]);

        try {
            $product_item = new ProductItems();

            $product_item->product_id = $request->product_field_code;
            $product_item->purchase_order_id = $request->po_id;
            $product_item->item_count = $request->product_field_ordered;
            $product_item->unit_cost = $request->product_field_eachcost;
            $product_item->staff_id = Auth::user()->staff->id;

            $product_item->save();

            echo 1;
        } catch (\Exception $ex) {
            echo 0;
        }
    }

    public function getProductItems($po_id) {
        $product_items = ProductItems::where('purchase_order_id', $po_id)
                ->get();

        $product_items_array = [];

        foreach ($product_items as $item) {
            $temp_array = [
                'product_item_id' => $item->id,
                'product_code' => $item->product->code,
                'product_description' => $item->product->description,
                'item_count' => $item->item_count,
                'unit_cost' => $item->unit_cost
            ];

            array_push($product_items_array, $temp_array);
        }

        echo json_encode($product_items_array);
    }

    public function deletePoProduct(Request $request) {
        try {
            ProductItems::destroy($request->id);
            echo 1;
        } catch (\Exception $e) {
            echo 0;
        }
    }

    public function printPurchaseOrder($id) {
        $company = Company::find(1);

        $purchase_order = PurchaseOrder::find($id);

        $product_items = $purchase_order->productItems;

        $product_items_array = [];

        $i = 1;
        $po_total = 0;

        foreach ($product_items as $item) {
            $amount = $item->item_count * $item->unit_cost;
            $po_total += $amount;

            $tmp = [
                'no' => $i,
                'product_code' => $item->product->code,
                'product_desc' => $item->product->description,
                'product_count' => $item->item_count,
                'product_unit_cost' => $item->unit_cost,
                'product_amount' => number_format($amount, 2)
            ];

            $i++;
            array_push($product_items_array, $tmp);
        }

        return view('purchaseorder.po-print', [
            'purchase_order' => $purchase_order,
            'product_items' => $product_items_array,
            'company' => $company,
            'po_total' => number_format($po_total, 2)
        ]);
    }

}