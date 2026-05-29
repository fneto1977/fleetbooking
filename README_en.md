# FleetBooking Plugin for GLPI 11

This plugin allows the management of fleet (vehicle) reservations through an approval workflow based on GLPI requests and tickets.

## Requirements

- **GLPI**: 11.0.0 or higher
- **PHP**: 8.1 or higher (8.2+ recommended)
- **Database**: MySQL 5.7+ or MariaDB 10.3+

## Features

- **Approval Workflow**: Users request a vehicle, which automatically creates a ticket for the group manager.
- **Availability Validation**: Checks for date/time conflicts, holidays, and business hours.
- **Visual Calendar**: Visualization of approved reservations and pending requests.
- **Entity Configuration**: Definition of business hours, colors, and ticket categories per entity.

## Installation

1. Clone or download this repository into the `plugins/fleetbooking` folder of your GLPI.
2. Go to **Setup > Plugins**.
3. Click on **Install** (floppy disk icon) and then **Enable** (check icon).

## Configuration

1. Go to **Administration > Entities**.
2. Select the desired entity and click on the **Fleet Booking Configuration** tab.
3. Define the **Default ITIL Category** and the **Vehicles ItemType** (e.g., computers, peripherals, or a generic fleet object).
4. Configure business hours and colors.
5. In **Tools > Fleet Booking Request > Fleet Group Managers**, map your groups to their respective managers.
6. In **Tools > Fleet Booking Request > Fleet Holidays**, add holidays to block reservations on those days.

## Usage

- **Users**: Access via **Tools > Fleet Booking Request** to request a reservation.
- **Managers**: Receive a ticket. In the **Fleet Approval** tab of the ticket, they can approve or reject the request.

## License

GPLv3+
