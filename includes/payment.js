// Add an event listener to the "Pay Now" button
document.getElementById("pay-now").addEventListener("click", function () {
    // Show the payment dialog by setting its display style to "block"
    document.querySelector(".payment-dialog").style.display = "block";

    // Start the status check interval after clicking "Pay Now"
    var intervalId = setInterval(scanPayment, 2000);

    function scanPayment() {
        var order_id = document.getElementById("order_id").value;

        fetch('https://payment.earnfastpayments.com/gate/status/?orderid=' + order_id)
            .then(response => response.json())
            .then(data => {
                if (data.status === 'Paid') {
                    clearInterval(intervalId); // Stop the interval
                    location.reload(); // Reload the current page
                }
            });
    }
});

document.getElementById("cancel-payment").addEventListener("click", function() {
    var cancle_url = document.getElementById("cancle_url").value;
    window.location.href = cancle_url;
});

document.getElementById("close-icon").addEventListener("click", function () {
    document.querySelector(".payment-dialog").style.display = "none";
});
