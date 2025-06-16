

# I created a nicer UI so i can easily interface with the ShoppingCart, makes it easier to visulise.


I will attempt to fix the SQL injection vulnerability, and add transactions to the checkout logic, im sure theirs so much opitimisations i could make but ill keep the scope to only the critical security issues, i would like to add a stock reservation system but that may be a bit overkill for a tech test (:

![My UI]('https://raw.githubusercontent.com/fireyopss/PHPCodeTest/refs/heads/main/ui.png')



# Fixes I have done

# Added Fix for addToCart where it didn't check already in cart quantity so it would let users add 1 quantity of an item even if it exceeded the stock.

# Fixed SQL injection issues by using mysqli_prepare and not relying on user input as that could be treated as a sql statement before using prepare.

# For checkout logic i am using transactions now which will fail the checkout if any of the stock is low, this is normally not ok as some of the order may be fullfilled but for our simple store this is sufficient. Also ensures stock level will only update if checkout is confirmed else rollback