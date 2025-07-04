<?php

session_start();

class ShoppingCart {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    // Add item to cart (prepared statement)
    public function addToCart($productId , $quantity) {
        $stmt = mysqli_prepare($this->db, "SELECT stock FROM products WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $productId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $product = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        
        if (!$product) {
            return false;
        }
        
        $currentQuantity = isset($_SESSION['cart'][$productId]) ? $_SESSION['cart'][$productId] : 0;

       
        $newQuantity = $currentQuantity + $quantity;
        if ($newQuantity <= 0) {
            return false; // Prevent adding zero or negative quantity
        }

        // Check stock
        if ($product['stock'] < $newQuantity) {
            return false; // Not enough stock
        }

    
        
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = array();
        }
        
        if (isset($_SESSION['cart'][$productId])) {
            $_SESSION['cart'][$productId] += $quantity;
        } else {
            $_SESSION['cart'][$productId] = $quantity;
        }
        
        return true;
    }
    
    // Calculate total price (prepared statement)
    public function getTotal() {
        $total = 0;
        
        if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
            return $total;
        }
        
        $stmt = mysqli_prepare($this->db, "SELECT price FROM products WHERE id = ?");
        
        foreach ($_SESSION['cart'] as $productId => $quantity) {
            mysqli_stmt_bind_param($stmt, "i", $productId);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $product = mysqli_fetch_assoc($result);
            
            if ($product) {
                $total += $product['price'] * $quantity;
            }
        }
        
        mysqli_stmt_close($stmt);
        
        return $total;
    }
    
    // Apply discount code (prepared statement)
    public function applyDiscount($code) {
        $stmt = mysqli_prepare($this->db, "SELECT percentage, expires FROM discount_codes WHERE code = ?");
        mysqli_stmt_bind_param($stmt, "s", $code);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $discount = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        
        if ($discount && $discount['expires'] > date('Y-m-d')) {
            $_SESSION['discount'] = $discount['percentage'];
            return true;
        }
        
        return false;
    }
    
    // Get final total with discount
    public function getFinalTotal() {
        $total = $this->getTotal();
        
        if (isset($_SESSION['discount'])) {
            $discountAmount = $total * ($_SESSION['discount'] / 100);
            $total = $total - $discountAmount;
        }
        
        return round($total, 2);
    }

    public function clearAllCart(){
        unset($_SESSION['cart']);
        header("Location: shop.php");
        exit;
    }
    
    // Process checkout with transaction and stock check
   public function checkout($userId, $paymentMethod) {
    if (empty($_SESSION['cart'])) {
        echo "Cart is empty";
        return false;
    }

    $total = $this->getFinalTotal();
    
    if ($total <= 0) {
        echo "Total must be greater than zero";
        return false;
    }

    // Start transaction
    $this->db->begin_transaction();

    try {
        // Re-check stock for each product in cart
        $stmtStockCheck = $this->db->prepare("SELECT stock FROM products WHERE id = ? FOR UPDATE");
        foreach ($_SESSION['cart'] as $productId => $quantity) {
            $stmtStockCheck->bind_param("i", $productId);
            $stmtStockCheck->execute();
            $result = $stmtStockCheck->get_result();
            $product = $result->fetch_assoc();

            if (!$product || $product['stock'] < $quantity) {
                $stmtStockCheck->close();
                $this->db->rollback();
                
                return false;
            }
        }
        $stmtStockCheck->close();

        // Insert order
        $stmtOrder = $this->db->prepare("INSERT INTO orders (user_id, total, payment_method, status) VALUES (?, ?, ?, 'pending')");
        $stmtOrder->bind_param("ids", $userId, $total, $paymentMethod);
        if (!$stmtOrder->execute()) {
            throw new Exception("Failed to create order");
        }
        $orderId = $this->db->insert_id;
        $stmtOrder->close();

        // Insert order items and update stock
        $stmtInsertItem = $this->db->prepare("INSERT INTO order_items (order_id, product_id, quantity) VALUES (?, ?, ?)");
        $stmtUpdateStock = $this->db->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
        
        foreach ($_SESSION['cart'] as $productId => $quantity) {
            $stmtInsertItem->bind_param("iii", $orderId, $productId, $quantity);
            if (!$stmtInsertItem->execute()) {
                throw new Exception("Failed to insert order item");
            }

            $stmtUpdateStock->bind_param("ii", $quantity, $productId);
            if (!$stmtUpdateStock->execute()) {
                throw new Exception("Failed to update stock");
            }
        }
        $stmtInsertItem->close();
        $stmtUpdateStock->close();

        // Commit transaction
        $this->db->commit();

        // Clear cart and discount session
        unset($_SESSION['cart'], $_SESSION['discount']);

        return $orderId;

    } catch (Exception $e) {
        $this->db->rollback();
        // Optionally log error: error_log($e->getMessage());
        return false;
    }
}

    // Get cart contents for display (prepared statement)
    public function getCartContents() {
        $contents = array();
        
        if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
            return $contents;
        }
        
        $stmt = mysqli_prepare($this->db, "SELECT * FROM products WHERE id = ?");
        
        foreach ($_SESSION['cart'] as $productId => $quantity) {
            mysqli_stmt_bind_param($stmt, "i", $productId);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $product = mysqli_fetch_assoc($result);
            
            if ($product) {
                $contents[] = array(
                    'product' => $product,
                    'quantity' => $quantity,
                    'subtotal' => $product['price'] * $quantity
                );
            }
        }
        
        mysqli_stmt_close($stmt);
        
        return $contents;
    }
}



// Usage example
$db = mysqli_connect('127.0.0.1', 'root', 'Ta123lo09@', 'vul');

if (isset($_POST['action'])) {
    $cart = new ShoppingCart($db);
    
    switch ($_POST['action']) {
        case 'cleardiscount':
            unset($_SESSION['discount']);
            header("Location: shop.php");
            break;
        case 'clear':
            $cart->clearAllCart();
            echo 'Cart cleared';
            break;
        case 'add':
            $result = $cart->addToCart($_POST['product_id'], $_POST['quantity']);
            echo $result ? 'Added to cart' : 'Failed to add';
            break;
            
        case 'checkout':
            $orderId = $cart->checkout($_POST['user_id'], $_POST['payment_method']);
            if ($orderId) {
                echo "Order created: " . $orderId;
            } else {
                echo "Checkout failed, stock may be insufficient or cart is empty";
            }
            break;
            
        case 'apply_discount':
            $result = $cart->applyDiscount($_POST['discount_code']);
            echo $result ? 'Discount applied' : 'Invalid discount code';
            header("Location: shop.php");
            break;
    }
}else {
     // Fetch all products
    $productsResult = mysqli_query($db, "SELECT * FROM products");

    // Instantiate cart to get contents and totals
    $cart = new ShoppingCart($db);
    $cartContents = $cart->getCartContents();
    $total = $cart->getTotal();
    $finalTotal = $cart->getFinalTotal();
    $discount = isset($_SESSION['discount']) ? $_SESSION['discount'] : 0;

    ?>

    <!DOCTYPE html>
    <html>
    <head>
        <title>Shopping Cart</title>
        <style>
            body { font-family: Arial, sans-serif; max-width: 900px; margin: auto; padding: 20px; }
            h1, h2 { text-align: center; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { padding: 8px; border: 1px solid #ddd; text-align: center; }
            button { padding: 5px 10px; background-color: #28a745; color: white; border: none; cursor: pointer; }
            button:hover { background-color: #218838; }
            input[type=number] { width: 60px; }
            .cart-summary { text-align: right; margin-bottom: 20px; }
            .discount { color: green; font-weight: bold; }
            .error { color: red; }
            form { margin-bottom: 20px; }
        </style>
        <script>
            function addToCart(productId) {
                const quantityInput = document.getElementById('quantity-' + productId);
                const quantity = quantityInput.value;

                fetch('', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'add',
                        product_id: productId,
                        quantity: quantity
                    })
                })
                .then(response => response.text())
                .then(text => {
                    alert(text);
                    location.reload();
                });
            }
        </script>
    </head>
    <body>

    <h1>Products</h1>

    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th>Price</th>
                <th>Stock</th>
                <th>Quantity</th>
                <th>Add to Cart</th>
            </tr>
        </thead>
        <tbody>
        <?php while ($product = mysqli_fetch_assoc($productsResult)) : ?>
            <tr>
                <td><?php echo htmlspecialchars($product['name']); ?></td>
                <td>$<?php echo number_format($product['price'], 2); ?></td>
                <td><?php echo (int)$product['stock']; ?></td>
                <td><input type="number" id="quantity-<?php echo $product['id']; ?>" value="1" min="1" max="<?php echo (int)$product['stock']; ?>"></td>
                <td><button onclick="addToCart(<?php echo $product['id']; ?>)">Add</button></td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>

    <h2>Your Cart</h2>

    <?php if (empty($cartContents)) : ?>
        <p>Your cart is empty.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cartContents as $item) : ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['product']['name']); ?></td>
                    <td><?php echo (int)$item['quantity']; ?></td>
                    <td>$<?php echo number_format($item['subtotal'], 2); ?></td>
                    
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

                <form method="POST">
                    <input type="hidden" name="action" value="clear">
                <button type="submit" name="clear_cart">Clear Cart</button>
                </form>

                 <form method="POST">
                    <input type="hidden" name="action" value="cleardiscount">
                <button type="submit" name="clear_cart">Clear Discount Codes</button>
                </form>

        <div class="cart-summary">
            <p>Total: $<?php echo number_format($total, 2); ?></p>
            <?php if ($discount > 0) : ?>
                <p class="discount">Discount: <?php echo $discount; ?>%</p>
                <p><strong>Final Total: $<?php echo number_format($finalTotal, 2); ?></strong></p>
            <?php endif; ?>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="checkout">
             <input type="hidden" name="user_id" value="1"> <!-- not recommended to trust user_id but for demo -->

            <label for="payment_method">Payment Method:</label>
            <select name="payment_method" id="payment_method" required>
                <option value="">Select</option>
                <option value="credit_card">Credit Card</option>
                <option value="paypal">PayPal</option>
            </select>
            <button type="submit">Checkout</button>
        </form>

        <form method="POST">
            <input type="hidden" name="action" value="apply_discount">
            <label for="discount_code">Discount Code:</label>
            <input type="text" name="discount_code" id="discount_code" required>
            <button type="submit">Apply</button>
        </form>
    <?php endif; ?>

    </body>
    </html>

    <?php
}