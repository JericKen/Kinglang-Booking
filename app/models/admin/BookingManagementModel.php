<?php
require_once __DIR__ . "/../../../config/database.php";

class BookingManagementModel {
    private $conn;

    public function __construct() {
        global $pdo;
        $this->conn = $pdo;
    }

    public function getAllBookings() {
        try {
            $stmt = $this->conn->prepare("
            SELECT b.booking_id, CONCAT(c.first_name, ' ', c.last_name) AS client_name, c.contact_number, b.destination, b.pickup_point, b.date_of_tour, b.end_of_tour, b.number_of_days, b.number_of_buses, b.status, b.total_cost, b.payment_status
            FROM bookings b
            JOIN clients c ON b.client_id = c.client_id
            ORDER BY b.date_of_tour DESC
            ");
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC); 
        }  catch (PDOException $e) {
            return "Database error";
        }
    }

    public function sendQuote($booking_id, $total_cost) {
        try {
            $stmt = $this->conn->prepare("UPDATE bookings SET total_cost = :total_cost, balance = :total_cost WHERE booking_id = :booking_id");
            $result = $stmt->execute([
                ":total_cost" => $total_cost,
                ":total_cost" => $total_cost,
                ":booking_id" => $booking_id
            ]);
            return $result;
        } catch (PDOException $e) {
            return "Database error";
        }
    }
}
?>