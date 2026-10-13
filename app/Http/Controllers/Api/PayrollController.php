<?php

namespace App\Http\Controllers\Api;

use App\Actions\Payroll\ApprovePayrollAction;
use App\Actions\Payroll\DeletePayrollAction;
use App\Actions\Payroll\GeneratePayrollsAction;
use App\Actions\Payroll\MarkPayrollPaidAction;
use App\Actions\Payroll\UpdatePayrollAction;
use App\Enums\PayrollStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\ExportPayrollRequest;
use App\Http\Requests\Payroll\GeneratePayrollRequest;
use App\Http\Requests\Payroll\ListPayrollRequest;
use App\Http\Requests\Payroll\UpdatePayrollRequest;
use App\Http\Resources\PayrollResource;
use App\Models\Payroll;
use App\Services\Payroll\PayrollExportService;
use App\Services\Pdf\PayslipPdfBuilder;

class PayrollController extends Controller
{
 
    public function index(ListPayrollRequest $request)
    {
    
        return Payroll::query()
            ->with(Payroll::WITH_LIST)
            ->visibleTo($request->user(), $request->validated())
            ->latest('period_start')
            ->paginate($this->perPage($request))
            ->through(fn (Payroll $payroll) => PayrollResource::make($payroll)->resolve());
    }

    public function show(Payroll $payroll)
    {
        $this->authorize('view', $payroll);

        return PayrollResource::make($payroll->load(Payroll::WITH_DETAIL));
    }

    
    public function payslip(Payroll $payroll)
    {
        $this->authorize('view', $payroll);

        if ($payroll->status === PayrollStatus::Draft) {
            throw new BusinessRuleException('الكشف لسا مسودة. بيتاح كشف الراتب بعد الاعتماد.');
        }

        return response(PayslipPdfBuilder::build($payroll), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="payslip-' . $payroll->id . '.pdf"',
        ]);
    }


    public function export(ExportPayrollRequest $request, PayrollExportService $exporter)
    {
        $file = $exporter->export($request->validated());

        return response($file['content'], 200, [
            'Content-Type' => $file['content_type'],
            'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
        ]);
    }

    public function generate(GeneratePayrollRequest $request, GeneratePayrollsAction $generate)
    {
        $result = $generate->execute($request->validated(), $request->user());

        if ($result->allConflicted()) {
            return response()->json([
                'message' => $result->conflictMessage(),
                'errors' => ['period_start' => ['الفترة بتتداخل مع كشف موجود']],
                'conflicts' => $result->conflicts,
            ], 422);
        }

        return response()->json([
            'generated' => PayrollResource::collection($result->generated)->resolve(),
            'skipped' => $result->skipped,
            'conflicts' => $result->conflicts,
            'warnings' => $result->warnings,
        ], 201);
    }

    public function update(UpdatePayrollRequest $request, Payroll $payroll, UpdatePayrollAction $update)
    {
        return PayrollResource::make($update->execute($payroll, $request->validated()));
    }

    public function approve(Payroll $payroll, ApprovePayrollAction $approve)
    {
        $this->authorize('approve', $payroll);

        return PayrollResource::make($approve->execute($payroll));
    }

    public function markPaid(Payroll $payroll, MarkPayrollPaidAction $markPaid)
    {
        $this->authorize('markPaid', $payroll);

        return PayrollResource::make($markPaid->execute($payroll));
    }

    public function destroy(Payroll $payroll, DeletePayrollAction $delete)
    {
        $this->authorize('delete', $payroll);

        $delete->execute($payroll);

        return response()->json(['message' => 'تم الحذف بنجاح']);
    }
}
