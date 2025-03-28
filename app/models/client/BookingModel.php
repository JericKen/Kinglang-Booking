<?php
require_once __DIR__ . "/../../../config/database.php";

class Booking {
    private $conn;
    
    public function __construct() {
        global $pdo;
        $this->conn = $pdo;
    }

    public function requestBooking($date_of_tour, $destination, $pickup_point, $number_of_days, $number_of_buses, $user_id) {
        $end_of_tour = date("Y-m-d", strtotime($date_of_tour . " + $number_of_days days"));

        try {
            $available_buses = $this->findAvailableBuses($date_of_tour, $end_of_tour, $number_of_buses);

            if (!$available_buses) {
                return "Not enough buses available.";
            }

            $stmt = $this->conn->prepare("INSERT INTO bookings (date_of_tour, end_of_tour, destination, pickup_point, number_of_days, number_of_buses, user_id) VALUES (:date_of_tour, :end_of_tour, :destination, :pickup_point, :number_of_days, :number_of_buses, :user_id)");
            $stmt->execute([
                ":date_of_tour" => $date_of_tour,
                ":end_of_tour" => $end_of_tour,
                ":destination" => $destination,
                ":pickup_point" => $pickup_point,
                ":number_of_days" => $number_of_days,       
                ":number_of_buses" => $number_of_buses,
                ":user_id" => $user_id
            ]);

            $booking_id = $this->conn->lastInsertID(); // get the added booking id to insert it in booking buses table
            
            foreach ($available_buses as $bus_id) {
                $stmt = $this->conn->prepare("INSERT INTO booking_buses (booking_id, bus_id) VALUES (:booking_id, :bus_id)");
                $stmt->execute([":booking_id" => $booking_id, ":bus_id" => $bus_id]);
            }

            return "success";
        } catch (PDOException $e) {
            return "Database error: $e";
        }
    }

    public function requestReschedBooking($number_of_days, $number_of_buses, $date_of_tour, $booking_id, $user_id) {
        // update booking   
        $end_of_tour = date("Y-m-d", strtotime($date_of_tour . " + $number_of_days days"));

        try {  
            $available_buses = $this->findAvailableBuses($date_of_tour, $end_of_tour, $number_of_buses);

            if (count($available_buses) < $number_of_buses) return "Not enough available buses.";

            if ($this->bookingIsNotConfirmed($booking_id)) {
                $stmt = $this->conn->prepare("UPDATE bookings SET date_of_tour = :date_of_tour, end_of_tour = :end_of_tour WHERE booking_id = :booking_id");
                $stmt->execute([
                    ":date_of_tour" => $date_of_tour,
                    ":end_of_tour" => $end_of_tour,
                    ":booking_id" => $booking_id
                ]);
                return "rescheduled";
            } 

            if ($this->bookingExistsInReschedRequests($booking_id)) {
                $stmt = $this->conn->prepare("UPDATE reschedule_requests SET new_date_of_tour = :new_date_of_tour, new_end_of_tour = :new_end_of_tour WHERE booking_id = :booking_id");
                $stmt->execute([
                    ":new_date_of_tour" => $date_of_tour,
                    ":new_end_of_tour" => $end_of_tour,
                    ":booking_id" => $booking_id
                ]);
                return "success";
            }

            $stmt = $this->conn->prepare("INSERT INTO reschedule_requests (new_date_of_tour, new_end_of_tour, booking_id, user_id) VALUES (:new_date_of_tour, :new_end_of_tour, :booking_id, :user_id)");
            $stmt->execute([
                ":new_date_of_tour" => $date_of_tour,
                ":new_end_of_tour" => $end_of_tour,
                ":booking_id" => $booking_id,
                ":user_id" => $user_id
            ]);

            return "success";
        } catch (PDOException $e) {
            return "Database error: $e";
        }
    }

    public function bookingExistsInReschedRequests($booking_id) {
        try {
            $stmt = $this->conn->prepare("SELECT * FROM reschedule_requests WHERE booking_id = :booking_id AND status != 'Confirmed'");
            $stmt->execute([":booking_id" => $booking_id]);
            $resched_request = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($resched_request) {
                return true;
            } else {
                return false;
            }
        } catch (PDOException $e) {
            return "Database error: $e";
        }
    }

    public function bookingIsNotConfirmed($booking_id) {
        try {
            $stmt = $this->conn->prepare("SELECT * FROM bookings WHERE booking_id = :booking_id");
            $stmt->execute([":booking_id" => $booking_id]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($booking["status"] === "confirmed" || $booking["total_cost"] !== NULL) {
                return false;
            } else {
                return true;
            }
        } catch (PDOException $e) {
            return "Database error: $e";
        }
    }

    public function findAvailableBuses($date_of_tour, $end_of_tour, $number_of_buses) {
        try {
            $stmt = $this->conn->prepare("
                SELECT bus_id
                FROM buses
                WHERE status = 'active'
                AND bus_id NOT IN (
                    SELECT bb.bus_id
                    FROM booking_buses bb
                    JOIN bookings bo ON bb.booking_id = bo.booking_id
                    WHERE bo.status = 'confirmed' 
                    AND (
                        (bo.date_of_tour <= :date_of_tour AND bo.end_of_tour >= :date_of_tour)
                        OR
                        (bo.date_of_tour <= :end_of_tour AND bo.end_of_tour >= :end_of_tour)
                        OR
                        (bo.date_of_tour >= :date_of_tour AND bo.end_of_tour <= :end_of_tour)
                    )
                )
                LIMIT :number_of_buses;
            ");
            $stmt->bindParam(":date_of_tour", $date_of_tour);
            $stmt->bindParam(":end_of_tour", $end_of_tour);
            $stmt->bindParam(":number_of_buses", $number_of_buses, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_COLUMN);       
        } catch (PDOException $e) {
            return "Database error: $e";
        }
    }

    public function getAllBookings($user_id, $status, $column, $order) {
        $allowed_status = ["pending", "confirmed", "canceled", "rejected", "completed", "all"];
        $status = in_array($status, $allowed_status) ? $status : "all";
        $status = $status === "all" ? "" : " AND status = '$status'";

        $allowed_columns = ["destination", "date_of_tour", "end_of_tour", "number_of_days", "number_of_buses", "total_cost", "balance", "status", "payment_status"];
        $column = in_array($column, $allowed_columns) ? $column : "date_of_tour";
        $order = $order === "asc" ? "ASC" : "DESC";
        try {
            $stmt = $this->conn->prepare("
                SELECT * FROM bookings 
                WHERE user_id = :user_id
                $status
                ORDER BY $column $order
            ");
            $stmt->execute([ ":user_id" => $user_id ]);

            $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $bookings ?: [];
        } catch (PDOException $e) {
            return "Database error: $e";
        }
    }

    public function updatePastBookings() {
        try {
            $stmt = $this->conn->prepare("UPDATE bookings SET status = 'Completed' WHERE end_of_tour < CURDATE() AND status != 'completed' AND balance = 0");
            $stmt->execute();
            return "Updated successfully!";
        } catch (PDOException $e) {
            return "Database error: $e";
        }
    }

    // payment
    public function addPayment($booking_id, $user_id, $amount, $payment_method) {
        try {
            $stmt = $this->conn->prepare("INSERT INTO payments (booking_id, user_id, amount, payment_method) VALUES (:booking_id, :user_id, :amount, :payment_method)");
            $stmt->execute([
                ":booking_id" => $booking_id,
                ":user_id" => $user_id,
                ":amount" => $amount,
                ":payment_method" => $payment_method
            ]);
            $this->updatePaymentStatus($booking_id);
            return true;
        } catch (PDOException $e) {
            return "Database error";
        }
    }

    public function updatePaymentStatus($booking_id) {
        try {
            $stmt = $this->conn->prepare("SELECT SUM(amount) AS total_paid FROM payments WHERE booking_id = :booking_id");
            $stmt->execute([":booking_id" => $booking_id]);
            $total_paid = $stmt->fetch(PDO::FETCH_ASSOC)["total_paid"] ?? 0;

            $stmt = $this->conn->prepare("SELECT total_cost, balance FROM bookings WHERE booking_id = :booking_id");
            $stmt->execute([":booking_id" => $booking_id]);
            $total_cost = $stmt->fetch(PDO::FETCH_ASSOC)["total_cost"] ?? 0;

            $balance = $total_cost - $total_paid;   

            $new_status = "Unpaid";
            if ($total_paid > 0 && $total_paid < $total_cost) {
                $new_status = "partially paid";
            } elseif ($total_paid >= $total_cost) {
                $new_status = "paid";
            }

            $stmt = $this->conn->prepare("UPDATE bookings SET payment_status = :payment_status, status = 'Confirmed', balance = :balance WHERE booking_id = :booking_id");
            $stmt->execute([
                ":payment_status" => $new_status,
                ":booking_id" => $booking_id,
                ":balance" => $balance
            ]);

        } catch (PDOException $e) {
            return "Database error";
        }
    }

    
}


?>