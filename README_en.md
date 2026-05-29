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

## Post-Installation Actions and Configuration

After installing and enabling the plugin, strictly follow the steps below to ensure it works correctly:

1. **Entity Configuration:**
   - Go to **Administration > Entities**, select the desired entity, and click on the **Fleet Booking Configuration** tab.
   - Define the **Default ITIL Category** that reservation tickets will use, and the **Vehicles ItemType** (e.g., computers, or a generic object). This permission/category setup is essential for ticket creation within the entity.
   - Configure business hours and colors.

2. **Profile Permissions:**
   - Go to **Administration > Profiles**.
   - You must grant access and administration permissions for the plugin to the profiles of users who will manage (approve) and request reservations. Check the plugin's corresponding tab within the profile settings.

3. **Group Management:**
   - Create or edit GLPI user groups in **Administration > Groups**.
   - Define the **manager** of each group. The plugin uses this information to automatically route the reservation request (ticket) to the correct manager of the requester's group.
   - In **Tools > Fleet Booking Request > Fleet Group Managers**, you can also configure additional mappings if needed.

4. **Vehicle Registration (Assets):**
   - Create your vehicles within the asset list of the type selected in step 1 (e.g., Computers or Generic Objects).
   - **Naming suggestion:** Use the format `<Name>-<Plate>` (e.g., `Civic-ABC1234`) for the asset name to facilitate visual identification during booking.

5. **Holidays (Optional):**
   - In **Tools > Fleet Booking Request > Fleet Holidays**, add local holidays to block new reservations on those days.

## Usage

- **Users**: Access via **Tools > Fleet Booking Request** to request a reservation.
- **Managers**: Receive a ticket. In the **Fleet Approval** tab of the ticket, they can approve or reject the request.

## License

GPLv3+
