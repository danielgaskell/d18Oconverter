library(dplyr)
library(tidync)
library(jsonlite)

write_json(na.omit(tidync("lgmDA_hol_Ocn_annual.nc") %>%
                       hyper_tibble() %>%
                       mutate(lat = round(lat, 2),
                              lon = round(ifelse(lon < 180, lon, lon - 360), 2),
                              d18O = round(d18osw, 2)) %>%
                       select(d18O, lat, lon)),
           "tierney_hol.json")

write_json(na.omit(tidync("lgmDA_lgm_Ocn_annual.nc") %>%
                       hyper_tibble() %>%
                       mutate(lat = round(lat, 2),
                              lon = round(ifelse(lon < 180, lon, lon - 360), 2),
                              d18O = round(d18osw, 2)) %>%
                       select(d18O, lat, lon)),
           "tierney_lgm.json")
