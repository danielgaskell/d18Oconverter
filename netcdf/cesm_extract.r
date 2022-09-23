library(dplyr)
library(tidync)
library(jsonlite)

prep_cesm <- function(filename, dimension, init_value) {
    netcdf <- tidync(filename)
    netcdf <- netcdf %>% hyper_tibble() %>%
        full_join(netcdf %>% activate(dimension) %>% hyper_tibble())
    na.omit(netcdf %>%
        mutate(lat = round(TLAT, 2),
               lon = round(ifelse(TLONG < 180, TLONG, TLONG - 360), 2),
               d18O = (R18O - 1.0) * 1000.0 - init_value) %>% # per Zhu et al. (2020)
        group_by(lat, lon) %>%
        summarize(d18O = round(mean(d18O, na.rm=T), 2),
                  TEMP = mean(TEMP, na.rm=T)))
}

# write Zhu et al. (2020) JSON version
eocene_1x <- prep_cesm("b.e12.B1850C5CN.f19_g16.iPETM01x.02.pop.h.TEMP_SALT_R18O.2701-2800.climo.nc", "D3,D2", -1)
eocene_3x <- prep_cesm("b.e12.B1850C5CN.f19_g16.iPETM03x.03.pop.h.TEMP_SALT_R18O.2101-2200.climo.nc", "D3,D2", -1)
eocene_6x <- prep_cesm("b.e12.B1850C5CN.f19_g16.iPETM06x.09.pop.h.TEMP_SALT_R18O.2101-2200.climo.nc", "D3,D2", -1)
eocene_9x <- prep_cesm("b.e12.B1850C5CN.f19_g16.iPETM09x.02.pop.h.TEMP_SALT_R18O.2101-2200.climo.nc", "D3,D2", -1)
write_json(eocene_1x %>% rename(`0` = d18O) %>% select(lat, lon, `0`) %>%
               full_join(eocene_3x %>% rename(`1` = d18O) %>% select(lat, lon, `1`)) %>%
               full_join(eocene_6x %>% rename(`2` = d18O) %>% select(lat, lon, `2`)) %>%
               full_join(eocene_9x %>% rename(`3` = d18O) %>% select(lat, lon, `3`)), "zhu_2020_eocene.json")

# write Gaskell et al. (2022) JSON version
miocene_280 <- prep_cesm("surface_props_B.MMIOx2_C5_280_WISOon.pop.h.ANN_concat.nc", "D1,D0", -0.68)
miocene_400 <- prep_cesm("surface_props_B.MMIOx2_C5_400_WISOon.pop.h.ANN_concat.nc", "D1,D0", -0.68)
miocene_840 <- prep_cesm("surface_props_B.MMIOx2_C5_840_WISOon.pop.h.ANN_concat.nc", "D1,D0", -0.68)
write_json(miocene_280 %>% rename(`0` = d18O) %>% select(lat, lon, `0`) %>%
               full_join(miocene_400 %>% rename(`1` = d18O) %>% select(lat, lon, `1`)) %>%
               full_join(miocene_840 %>% rename(`2` = d18O) %>% select(lat, lon, `2`)), "gaskell_2022_miocene.json")