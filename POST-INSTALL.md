# Ações Pós-Instalação / Post-Installation Actions

## 🇧🇷 Português

Após instalar e ativar o plugin FleetBooking no GLPI, siga rigorosamente os passos abaixo para garantir o funcionamento correto do fluxo de reservas:

1. **Configuração da Entidade:**
   - Vá em **Administração > Entidades**, selecione a entidade desejada e acesse a aba **Fleet Booking Configuration**.
   - Defina a **Categoria ITIL Padrão** que os tickets de reserva usarão, e o **ItemType de Veículos** (ex: computadores, ou um Generic Object). Essa permissão/categoria é essencial para a criação do ticket pela entidade.
   - Configure os horários de funcionamento e cores.

2. **Permissões de Perfil:**
   - Vá em **Administração > Perfis**.
   - É necessário dar permissão de acesso e administração do plugin aos perfis dos usuários que farão a gestão (aprovação) e dos que solicitarão reservas. Verifique a aba correspondente ao plugin dentro da configuração de perfil.

3. **Gerenciamento de Grupos:**
   - Crie ou edite os grupos de usuários do GLPI em **Administração > Grupos**.
   - Defina quem é o **gerente do grupo**. O plugin usará esta informação para encaminhar a solicitação de reserva (ticket) diretamente ao responsável correto pelo grupo do solicitante.
   - Em **Ferramentas > Fleet Booking Request > Fleet Group Managers**, você também pode fazer o mapeamento adicional caso necessário.

4. **Cadastro de Veículos (Ativos):**
   - Na lista de ativos do tipo escolhido no passo 1 (ex: Computadores ou Objetos Genéricos), crie os seus veículos.
   - **Sugestão de nomenclatura:** Utilize o formato `<Nome>-<Placa>` (ex: `Celta-ABC1234`) no nome do ativo para facilitar a identificação visual na hora da reserva.

5. **Feriados (Opcional):**
   - Em **Ferramentas > Fleet Booking Request > Fleet Holidays**, adicione feriados locais para bloquear novas reservas nestes dias.

6. **Perfil Somente Leitura (Portaria):**
   Existem duas formas de conceder acesso de leitura à portaria:

   **Opção A — Automática (Recomendado):**
   - Vá em **Administração > Entidades**, selecione a entidade, aba **Fleet Booking Configuration**
   - No campo **Read-Only Profile (Portaria)**, selecione o perfil desejado
   - Ao salvar, o plugin automaticamente concede permissão de leitura (`asset_veiculofrota` READ) a esse perfil
   - O perfil também precisa ter `fleetbooking_read` marcado (Administration > Profiles > Aba "Fleet Booking Rights")

   **Opção B — Manual (fallback):**
   1. Acesse **Administration > Profiles**, selecione o perfil da portaria
   2. Na aba **"Fleet Booking Rights"**, marque apenas ☑ **Read** (`fleetbooking_read`)
   3. Na aba **"Administration"**, localize **"Veículo-frota"** (`asset_veiculofrota`) e marque ☑ **Read**
   4. Configure a entidade com **Recursive: Yes** para ver sub-entidades
   5. Salve
   
   **Verificação (SQL):**
   ```sql
   SELECT p.name, pr.name AS right_name, pr.rights
   FROM glpi_profilerights pr
   JOIN glpi_profiles p ON p.id = pr.profiles_id
   WHERE pr.name IN ('fleetbooking_read', 'asset_veiculofrota')
   AND pr.rights > 0;
   ```

---

## 🇺🇸 English

After installing and enabling the FleetBooking plugin in GLPI, strictly follow the steps below to ensure the reservation workflow works correctly:

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

6. **Read-Only Profile (Gatehouse/Portaria):**
   There are two ways to grant read-only access to the gatehouse:

   **Option A — Automatic (Recommended):**
   - Go to **Administration > Entities**, select the entity, **Fleet Booking Configuration** tab
   - In the **Read-Only Profile (Portaria)** dropdown, select the desired profile
   - Upon saving, the plugin automatically grants `asset_veiculofrota` READ permission to that profile
   - The profile must also have `fleetbooking_read` enabled (Administration > Profiles > "Fleet Booking Rights" tab)

   **Option B — Manual (fallback):**
   1. Go to **Administration > Profiles**, select the gatehouse profile
   2. Under the **"Fleet Booking Rights"** tab, check only ☑ **Read** (`fleetbooking_read`)
   3. Under the **"Administration"** tab, find **"Vehicle-Fleet"** (`asset_veiculofrota`) and check ☑ **Read**
   4. Set entity recursion to **Yes** to view sub-entities
   5. Save

   **Verification (SQL):**
   ```sql
   SELECT p.name, pr.name AS right_name, pr.rights
   FROM glpi_profilerights pr
   JOIN glpi_profiles p ON p.id = pr.profiles_id
   WHERE pr.name IN ('fleetbooking_read', 'asset_veiculofrota')
   AND pr.rights > 0;
   ```
