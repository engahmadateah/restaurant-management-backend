<?php

namespace App\Http\Controllers\Api;

use App\Actions\Reservations\CancelReservationAction;
use App\Actions\Reservations\CreateReservationAction;
use App\Actions\Reservations\DeleteReservationAction;
use App\Actions\Reservations\TransitionReservationAction;
use App\Actions\Reservations\UpdateReservationAction;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservation\CancelReservationRequest;
use App\Http\Requests\Reservation\ListReservationsRequest;
use App\Http\Requests\Reservation\StoreReservationRequest;
use App\Http\Requests\Reservation\UpdateReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Models\Reservation;

class ReservationController extends Controller
{
    public function index(ListReservationsRequest $request)
    {
        $reservations = Reservation::query()
            ->with(Reservation::WITH)
            ->filter($request->validated())
            ->orderBy('reservation_date')
            ->orderBy('reservation_time')
            ->get();

        return ReservationResource::collection($reservations);
    }

    public function store(StoreReservationRequest $request, CreateReservationAction $create)
    {
        $reservation = $create->execute($request->validated(), $request->user());

        return ReservationResource::make($reservation->load(Reservation::WITH))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Reservation $reservation)
    {
        $this->authorize('view', $reservation);

        return ReservationResource::make($reservation->load(Reservation::WITH));
    }

    public function update(UpdateReservationRequest $request, Reservation $reservation, UpdateReservationAction $update)
    {
        return ReservationResource::make($update->execute($reservation, $request->validated()));
    }

    public function destroy(Reservation $reservation, DeleteReservationAction $delete)
    {
        $this->authorize('delete', $reservation);

        $delete->execute($reservation);

        return response()->json(['message' => 'تم حذف الحجز بنجاح']);
    }

   
    public function confirm(Reservation $reservation, TransitionReservationAction $transition)
    {
        return $this->transitionTo($reservation, ReservationStatus::Confirmed, $transition);
    }

   
    public function cancel(CancelReservationRequest $request, Reservation $reservation, CancelReservationAction $cancel)
    {
        return $this->respond($cancel->execute($reservation, $request->validated('reason')));
    }

  
    public function complete(Reservation $reservation, TransitionReservationAction $transition)
    {
        return $this->transitionTo($reservation, ReservationStatus::Completed, $transition);
    }

  
    public function noShow(Reservation $reservation, TransitionReservationAction $transition)
    {
        return $this->transitionTo($reservation, ReservationStatus::NoShow, $transition);
    }

    private function transitionTo(Reservation $reservation, ReservationStatus $to, TransitionReservationAction $transition)
    {
        $this->authorize('transition', $reservation);

        return $this->respond($transition->execute($reservation, $to));
    }

    private function respond(Reservation $reservation)
    {
        return ReservationResource::make($reservation->fresh(Reservation::WITH));
    }
}
