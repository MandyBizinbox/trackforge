<?php
namespace App\Http\Controllers;
use App\Models\EquipmentCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EquipmentCategoryController extends Controller
{
    public function index(){ return EquipmentCategory::orderBy('sort_order')->orderBy('name')->get(); }
    public function store(Request $request){ $data=$request->validate(['name'=>'required','description'=>'nullable','sort_order'=>'nullable|integer']); $data['slug']=Str::slug($data['name']); return EquipmentCategory::create($data); }
    public function show(EquipmentCategory $category){ return $category; }
    public function update(Request $request, EquipmentCategory $category){ $data=$request->validate(['name'=>'required','description'=>'nullable','sort_order'=>'nullable|integer']); $data['slug']=Str::slug($data['name']); $category->update($data); return $category; }
    public function destroy(EquipmentCategory $category){ $category->delete(); return response()->json(['message'=>'Deleted']); }
}
