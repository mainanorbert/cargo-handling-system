
## 1. Background Scenario

The system is a core microservice component for a modern Logistics-as-a-Service (LaaS) platform dedicated to bulk liquid port terminal management. It monitors the movement of high-volume palm oil products along this strict execution workflow:

**Vessel Discharge (Inbound Cargo) → Shore Storage Tanks → Truck Delivery (Outbound Cargo)**

## 2. Commodity Profiles

The system handles three distinct commodities, each with specific handling behaviors:

- **Crude Palm Oil (CPO):** Unrefined bulk oil requiring constant temperature control. Highly viscous and prone to standard volumetric variations.
- **Crude Palm Olein (CPOL):** The liquid fraction obtained from the fractionation of palm oil after crystallization. Commonly utilized for cooking oil formulations, possessing higher fluidity.
- **Palm Fatty Acid Distillate (PFAD):** A manufacturing byproduct refined from crude palm oil processing. Viscous material used heavily in industrial applications like soap-making and animal feed.

## 3. Technical & Domain Rules

### Capacity Boundaries

Physical storage units cannot exceed their specific volume limits. Attempting to force an overflow must result in a custom domain exception.

### Volumetric Loss Adjustment

Bulk liquid operations naturally incur a standard transit mass loss of **0.5%**. The processing core must dynamically factor this reduction into all inbound vessel flows.

### Time-Series Synchronization

Source logs may enter the message pipeline asynchronously or out of chronological order. The engine is required to re-sort records by timestamp before modifying balances.

### Atomic Operations

Business logic must insulate states against concurrent execution race conditions, protecting capacities from duplicate allocation collisions.

## 4. Target Framework Architecture

The application must map this pipeline to a Laravel and FilamentPHP project instead of using procedural scripts. It must utilize reactive frontend handlers, backend entity state hooks, and clear domain separation of concerns to provide an operational control dashboard.
