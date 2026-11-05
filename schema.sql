CREATE DATABASE IF NOT EXISTS byte_busters CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE byte_busters;

-- Re-importing wipes these tables so old versions can't clash
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS order_items, orders, cart_items, products, users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
                       id INT AUTO_INCREMENT PRIMARY KEY,
                       name VARCHAR(80) NOT NULL,
                       email VARCHAR(120) NOT NULL UNIQUE,
                       password_hash VARCHAR(255) NOT NULL,
                       role ENUM('user','admin') NOT NULL DEFAULT 'user',
                       phone VARCHAR(30) DEFAULT '',
                       address VARCHAR(255) DEFAULT '',
                       created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE products (
                          id INT AUTO_INCREMENT PRIMARY KEY,
                          name VARCHAR(120) NOT NULL,
                          category VARCHAR(40) NOT NULL,
                          description TEXT,
                          price DECIMAL(10,2) NOT NULL,
                          stock INT NOT NULL DEFAULT 0,
                          image VARCHAR(80) DEFAULT NULL,
                          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                          INDEX (category)
);

CREATE TABLE cart_items (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            user_id INT NOT NULL,
                            product_id INT NOT NULL,
                            qty INT NOT NULL DEFAULT 1,
                            UNIQUE KEY one_per_product (user_id, product_id),
                            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE TABLE orders (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id INT NOT NULL,
                        subtotal DECIMAL(10,2) NOT NULL,
                        discount DECIMAL(10,2) NOT NULL DEFAULT 0,
                        total DECIMAL(10,2) NOT NULL,
                        method ENUM('delivery','pickup') NOT NULL DEFAULT 'delivery',
                        address VARCHAR(255) DEFAULT '',
                        eta_date DATE NOT NULL,
                        status ENUM('pending','processing','shipped','completed','cancelled') NOT NULL DEFAULT 'pending',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE order_items (
                             id INT AUTO_INCREMENT PRIMARY KEY,
                             order_id INT NOT NULL,
                             product_id INT NULL,
                             name VARCHAR(120) NOT NULL,
                             price DECIMAL(10,2) NOT NULL,
                             qty INT NOT NULL,
                             FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
                             FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
);

-- Sample products (placeholder prices, edit freely)
INSERT INTO products (name, category, description, price, stock) VALUES
                                                                     ('1 kΩ Resistor Pack', 'Passives', 'Pack of 10 carbon film resistors, 1/4 W, 5% tolerance.', 35, 200),
                                                                     ('220 Ω Resistor Pack', 'Passives', 'Pack of 10 carbon film resistors, 1/4 W. The usual pick for LEDs on 5 V.', 35, 200),
                                                                     ('Ceramic Capacitor Assortment', 'Passives', 'Mixed ceramic capacitors from 10 pF to 100 nF.', 150, 60),
                                                                     ('Mini Breadboard', 'Prototyping', '400 tie points, no soldering needed.', 89, 80),
                                                                     ('Jumper Wire Set', 'Prototyping', '40 pieces, male to male, 20 cm.', 120, 90),
                                                                     ('Assorted LEDs', 'Prototyping', '50 pieces, 5 mm, mixed colors.', 95, 120),
                                                                     ('NE555 Timer IC', 'Microcontrollers', 'DIP-8 timer IC for astable and monostable circuits.', 48, 150),
                                                                     ('Arduino Uno R3', 'Microcontrollers', 'ATmega328P board with USB cable, 14 digital I/O pins.', 650, 25),
                                                                     ('ESP32 DevKit', 'Microcontrollers', 'Dual-core Wi-Fi and Bluetooth board, 30 pins.', 420, 30),
                                                                     ('DHT22 Temperature and Humidity Sensor', 'Sensors', 'Digital sensor, 0 to 100% humidity, -40 to 80 °C.', 280, 40),
                                                                     ('HC-SR04 Ultrasonic Sensor', 'Sensors', 'Distance sensor, 2 cm to 400 cm.', 110, 55),
                                                                     ('Soil Moisture Sensor', 'Sensors', 'Analog and digital output for plant monitoring.', 85, 45),
                                                                     ('LED Traffic Light Kit', 'Kits', 'Three LEDs, resistors, breadboard and wiring guide.', 380, 20),
                                                                     ('Temperature Monitor Kit', 'Kits', 'Arduino-compatible kit with sensor, LCD and code.', 890, 12);

-- Fixed admin account: admin@bytebusters.local / BB-admin-2026 (change the password after your first login)
INSERT INTO users (name, email, password_hash, role) VALUES
    ('Admin', 'admin@bytebusters.local', '$2a$10$Rc3HxugsF8xXoAN7.GwoZeXI1RpjZVaMxqhKY2EZmuUfDfUpEf5cO', 'admin');