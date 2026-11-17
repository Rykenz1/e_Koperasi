// Pre-fill Edit Outlet Modal
function openEditOutletModal(outlet) {
    document.getElementById('edit_outlet_id').value = outlet.outlet_id;
    document.getElementById('edit_outlet_name').value = outlet.outlet_name;
    document.getElementById('edit_location').value = outlet.location || outlet.location_description || '';
    document.getElementById('edit_contact_number').value = outlet.contact_number || '';
    document.getElementById('edit_status').value = outlet.status || 'Active';

    // Split "08:00 AM - 10:00 PM" into Open and Close time inputs
    let openVal = '', closeVal = '';
    if (outlet.operating_hours && outlet.operating_hours.includes('-')) {
        const parts = outlet.operating_hours.split('-');
        openVal = parseTo24Hour(parts[0]);
        closeVal = parseTo24Hour(parts[1]);
    }
    document.getElementById('edit_open_time').value = openVal;
    document.getElementById('edit_close_time').value = closeVal;

    openModal('editOutletModal');
}

// Helper: Convert "8 am" or "08:00 AM" to "08:00" for <input type="time">
function parseTo24Hour(timeStr) {
    if (!timeStr) return '';
    const match = timeStr.trim().match(/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$/i);
    if (!match) return '';

    let hours = parseInt(match[1], 10);
    const minutes = match[2] || '00';
    const period = match[3] ? match[3].toLowerCase() : null;

    if (period === 'pm' && hours < 12) hours += 12;

    if (period === 'am' && hours === 12) hours = 0;

    return String(hours).padStart(2, '0') + ':' + minutes;
}

// Hide Outlet Dropdown if 'Superadmin' role is selected
function toggleOutletDropdown() {
    const role = document.getElementById('staff_role_select').value;
    const outletGroup = document.getElementById('outlet_assign_group');
    outletGroup.style.display = (role === 'Staff') ? 'block' : 'none';
}